<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Status\StatusService;
use Nebrix\Tests\FeatureTestCase;

/**
 * Public status page, incidents, maintenance announcements, notifications.
 */
final class StatusPageTest extends FeatureTestCase
{
    private int $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 10:00:00'));
        $this->integration = $this->app->container()->get(IntegrationRepository::class)->create('proxmox', 'Cluster FSN', true, ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'a@pve!b', 'verify_tls' => true], ['token_secret' => 'x']);
    }

    private function customerWithService(?int $integrationId): \Nebrix\Auth\User
    {
        $customer = $this->createUser();
        $this->app->container()->get(ServiceRepository::class)->create([
            'user_id' => $customer->id, 'integration_id' => $integrationId, 'category' => 'lxc', 'name' => 'web', 'status' => Service::STATUS_ACTIVE,
            'billing_cycle' => 'monthly', 'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'config' => [],
        ]);

        return $customer;
    }

    public function testIncidentLifecycleOnThePublicPage(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/status/components', ['name' => 'Cloud servers FSN', 'group_name' => 'Servers', 'status' => 'operational', 'integration_id' => (string) $this->integration, 'is_visible' => '1', 'sort_order' => '1']);
        $this->post('/admin/status/components', ['name' => 'Customer area', 'group_name' => 'Panel', 'status' => 'operational', 'is_visible' => '1']);
        $this->post('/admin/status/components', ['name' => 'Internal', 'status' => 'operational']);
        $servers = (int) $this->db->fetchValue("SELECT `id` FROM `nx_status_components` WHERE `name` = 'Cloud servers FSN'");

        // Public, without sign-in: all operational; hidden components are not shown.
        $this->cookies = [];
        $page = $this->get('/status')->body();
        self::assertStringContainsString('All systems operational', $page);
        self::assertStringContainsString('Cloud servers FSN', $page);
        self::assertStringNotContainsString('Internal', $page);
        self::assertStringNotContainsString('Cluster FSN', $page, 'Integration names stay internal.');

        $affected = $this->customerWithService($this->integration);
        $other = $this->customerWithService(null);
        $this->actingAs($this->createUser(Role::SUPPORT));
        $this->post('/admin/status/incidents', [
            'type' => 'incident', 'title' => 'Network <b>problems</b>', 'impact' => 'major', 'status' => 'investigating',
            'component_ids' => [(string) $servers], 'message' => "We are investigating packet loss.\nNext update in 30 minutes.", 'is_published' => '1', 'notify' => '1',
        ]);
        $incidentId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_status_incidents`');
        self::assertGreaterThan(0, $incidentId);
        // Only customers with a service on the affected integration are notified.
        $recipients = array_column($this->db->fetchAll("SELECT `payload` FROM `nx_jobs` WHERE `type` = 'mail.send'"), 'payload');
        self::assertCount(1, $recipients);
        self::assertSame($affected->email, $this->lastMail('status_incident')['to'] ?? null);
        self::assertNotSame($other->email, $this->lastMail('status_incident')['to'] ?? null);

        $this->cookies = [];
        $page = $this->get('/status')->body();
        self::assertStringContainsString('Partial outage', $page);
        self::assertStringContainsString('Network &lt;b&gt;problems&lt;/b&gt;', $page, 'Titles are escaped.');
        self::assertStringContainsString('We are investigating packet loss.<br />', $page);
        $json = json_decode($this->get('/status.json')->body(), true);
        self::assertSame('partial_outage', $json['status']);
        self::assertEquals(['Cloud servers FSN' => 'partial_outage', 'Customer area' => 'operational'], array_column($json['components'], 'status', 'name'));
        self::assertSame('Network <b>problems</b>', $json['incidents'][0]['title']);

        // The dashboard of a customer shows the notice.
        $this->actingAs($affected);
        self::assertStringContainsString('Details on the status page', $this->get('/dashboard')->body());

        // Resolved: back to operational, listed under the past 14 days.
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/status/incidents/' . $incidentId . '/updates', ['status' => 'resolved', 'message' => 'Fixed.']);
        $this->cookies = [];
        $page = $this->get('/status')->body();
        self::assertStringContainsString('All systems operational', $page);
        self::assertStringContainsString('Past 14 days', $page);
        self::assertNotNull($this->db->fetchValue('SELECT `resolved_at` FROM `nx_status_incidents` WHERE `id` = ?', [$incidentId]));
    }

    public function testMaintenanceIsAnnouncedStartedAndCompleted(): void
    {
        $status = $this->app->container()->get(StatusService::class);
        $admin = $this->createUser(Role::ADMIN);
        $component = $status->saveComponent(null, ['name' => 'Webhosting', 'description' => null, 'group_name' => null, 'status' => 'operational', 'integration_id' => null, 'is_visible' => true, 'sort_order' => 0], $admin->id, null);
        $customer = $this->customerWithService(null);

        $this->actingAs($admin);
        // Times are entered in the panel time zone (Europe/Berlin = UTC+2 in July).
        $this->post('/admin/status/incidents', [
            'type' => 'maintenance', 'title' => 'Kernel updates', 'status' => 'scheduled', 'starts_at' => '2026-07-02T22:00', 'ends_at' => '2026-07-03T01:00',
            'component_ids' => [(string) $component], 'message' => 'Short reboots of the web servers.', 'is_published' => '1', 'notify' => '1',
        ]);
        $maintenance = (array) $this->db->fetchOne('SELECT * FROM `nx_status_incidents`');
        self::assertSame(['2026-07-02 20:00:00', '2026-07-02 23:00:00', 'scheduled'], [$maintenance['starts_at'], $maintenance['ends_at'], $maintenance['status']]);
        self::assertSame($customer->email, $this->lastMail('status_maintenance')['to'] ?? null, 'No component with an integration: all customers with an active service.');
        self::assertStringContainsString('22:00', (string) ($this->lastMail('status_maintenance')['html'] ?? ''), 'The mail shows the local time.');

        $this->cookies = [];
        $page = $this->get('/status')->body();
        self::assertStringContainsString('Scheduled maintenance', $page);
        self::assertStringContainsString('All systems operational', $page, 'Future maintenance does not change the state yet.');
        $this->actingAs($customer);
        self::assertStringContainsString('Kernel updates', $this->get('/dashboard')->body(), 'Announced within 72 hours.');

        $this->clock()->freeze(new DateTimeImmutable('2026-07-02 20:05:00'));
        self::assertSame(['started' => 1, 'completed' => 0], $status->advanceMaintenance());
        $this->cookies = [];
        self::assertStringContainsString('Maintenance in progress', $this->get('/status')->body());
        self::assertSame('maintenance', json_decode($this->get('/status.json')->body(), true)['components'][0]['status']);

        $this->clock()->freeze(new DateTimeImmutable('2026-07-02 23:01:00'));
        self::assertSame(['started' => 0, 'completed' => 1], $status->advanceMaintenance());
        self::assertStringContainsString('All systems operational', $this->get('/status')->body());
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_status_updates`'));
    }

    public function testDraftsHealthChecksPermissionsAndValidation(): void
    {
        $status = $this->app->container()->get(StatusService::class);
        $admin = $this->createUser(Role::ADMIN);
        $component = $status->saveComponent(null, ['name' => 'Cloud', 'description' => null, 'group_name' => null, 'status' => 'operational', 'integration_id' => $this->integration, 'is_visible' => true, 'sort_order' => 0], $admin->id, null);

        // A failed health check of the linked integration shows the component as degraded.
        $this->db->update('nx_integrations', ['health_status' => 'down'], ['id' => $this->integration]);
        self::assertSame('degraded', json_decode($this->get('/status.json')->body(), true)['status']);
        $this->db->update('nx_integrations', ['health_status' => 'healthy'], ['id' => $this->integration]);

        // Drafts are not public.
        $status->create(['type' => 'incident', 'title' => 'Draft only', 'impact' => 'critical', 'status' => 'investigating', 'starts_at' => $this->clock()->nowString(), 'ends_at' => null, 'is_published' => false, 'component_ids' => [$component], 'message' => 'x'], $admin->id, null, true);
        self::assertStringNotContainsString('Draft only', $this->get('/status')->body());
        self::assertSame('operational', json_decode($this->get('/status.json')->body(), true)['status']);
        self::assertNull($this->lastMail('status_incident'), 'Drafts send no mails.');

        // Hidden (internal) components are never named publicly, even when an incident affects them.
        $internal = $status->saveComponent(null, ['name' => 'Backup NAS internal', 'description' => null, 'group_name' => null, 'status' => 'operational', 'integration_id' => null, 'is_visible' => false, 'sort_order' => 0], $admin->id, null);
        $status->create(['type' => 'incident', 'title' => 'Storage problems', 'impact' => 'minor', 'status' => 'investigating', 'starts_at' => $this->clock()->nowString(), 'ends_at' => null, 'is_published' => true, 'component_ids' => [$internal], 'message' => 'x'], $admin->id, null, false);
        self::assertStringContainsString('Storage problems', $this->get('/status')->body());
        self::assertStringNotContainsString('Backup NAS internal', $this->get('/status')->body());
        self::assertStringNotContainsString('Backup NAS internal', $this->get('/status.json')->body());

        // Finance staff cannot manage the status page.
        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->get('/admin/status')->status());
        self::assertSame(403, $this->post('/admin/status/components', ['name' => 'X', 'status' => 'operational'])->status());

        // The end of a maintenance must be after its start.
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/status/incidents', ['type' => 'maintenance', 'title' => 'Bad window', 'status' => 'scheduled', 'starts_at' => '2026-07-05T10:00', 'ends_at' => '2026-07-05T09:00', 'message' => 'x']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_status_incidents` WHERE `title` = 'Bad window'"));
        self::assertStringContainsString('The end must be after the start.', $this->get('/admin/status/incidents/create', ['type' => 'maintenance'])->body());
    }
}
