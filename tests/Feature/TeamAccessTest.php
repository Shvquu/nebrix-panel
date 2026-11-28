<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\User;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Team access: invitations, permissions per service, billing access and
 * revocation – every lookup is checked server-side.
 */
final class TeamAccessTest extends FeatureTestCase
{
    private User $owner;

    private User $member;

    private int $server;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-06-25 12:00:00'));
        $this->owner = $this->createUser(attributes: ['company' => 'ACME GmbH']);
        $this->member = $this->createUser();
        $services = $this->app->container()->get(ServiceRepository::class);
        foreach (['server' => 'Web server', 'other' => 'Database server'] as $property => $name) {
            $this->{$property} = $services->create([
                'user_id' => $this->owner->id, 'category' => 'dedicated', 'name' => $name, 'status' => 'active',
                'billing_cycle' => 'monthly', 'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR',
                'next_due_at' => '2026-07-01 00:00:00', 'config' => [],
            ]);
            $services->setCredentials($this->{$property}, ['username' => 'root', 'password' => 'secret-' . $property]);
        }
    }

    /**
     * Invites the member and accepts as the member.
     *
     * @param array<string, mixed> $form
     */
    private function join(array $form): string
    {
        $this->actingAs($this->owner);
        $this->post('/account/team', $form + ['email' => strtoupper($this->member->email)]);
        $mail = $this->lastMail('team_invitation');
        self::assertNotNull($mail, 'Invitation e-mail queued.');
        self::assertSame(strtolower($this->member->email), $mail['to']);
        preg_match('#/team/invitations/([a-f0-9]{64})#', (string) $mail['html'], $match);
        self::assertNotEmpty($match[1] ?? null);

        $this->actingAs($this->member);
        self::assertSame(200, $this->get('/team/invitations/' . $match[1])->status());
        $this->post('/team/invitations/' . $match[1] . '/accept');

        return $match[1];
    }

    public function testAMemberSeesOnlyWhatWasGranted(): void
    {
        $token = $this->join(['permissions' => ['services.view'], 'services' => [(string) $this->server]]);
        self::assertSame('active', $this->db->fetchValue('SELECT status FROM nx_account_members WHERE member_id = ?', [$this->member->id]));
        self::assertNull($this->db->fetchValue('SELECT token_hash FROM nx_account_members WHERE member_id = ?', [$this->member->id]), 'Single use.');
        self::assertSame(404, $this->get('/team/invitations/' . $token)->status());

        $list = $this->get('/services')->body();
        self::assertStringContainsString('Web server', $list);
        self::assertStringNotContainsString('Database server', $list, 'Only the selected service is shared.');
        self::assertSame(200, $this->get('/services/' . $this->server)->status());
        self::assertSame(404, $this->get('/services/' . $this->other)->status());

        // View only: no access data, no actions, no console, no contract changes.
        self::assertSame(404, $this->get('/services/' . $this->server . '/credentials')->status());
        self::assertSame(404, $this->post('/services/' . $this->server . '/action', ['action' => 'reboot'])->status());
        self::assertSame(404, $this->get('/services/' . $this->server . '/console')->status());
        self::assertSame(404, $this->get('/services/' . $this->server . '/package')->status());
        self::assertSame(404, $this->post('/services/' . $this->server . '/cancellation/withdraw')->status());
        self::assertSame(404, $this->get('/services/' . $this->server . '/snapshots')->status());
        self::assertSame(404, $this->get('/services/' . $this->server . '/rdns')->status());
        self::assertSame(404, $this->get('/services/' . $this->server . '/dns')->status());
        self::assertStringNotContainsString('/cancel-contract?service=', $this->get('/services/' . $this->server)->body());
    }

    public function testCredentialsPermissionAndAllServices(): void
    {
        $this->join(['permissions' => ['services.credentials'], 'all_services' => '1']);

        self::assertSame(200, $this->get('/services/' . $this->other)->status(), 'All services, including the other one.');
        $credentials = $this->get('/services/' . $this->other . '/credentials');
        self::assertSame(200, $credentials->status());
        self::assertStringContainsString('secret-other', $credentials->body());
        self::assertSame(['services.credentials', 'services.view'], explode(',', (string) $this->db->fetchValue('SELECT permissions FROM nx_account_members WHERE member_id = ?', [$this->member->id])), 'Viewing is implied.');
    }

    public function testRevokingAndLockedOwnersEndAccessImmediately(): void
    {
        $this->join(['permissions' => ['services.view'], 'all_services' => '1']);
        self::assertSame(200, $this->get('/services/' . $this->server)->status());

        $this->db->update('nx_users', ['status' => 'locked'], ['id' => $this->owner->id]);
        self::assertSame(404, $this->get('/services/' . $this->server)->status(), 'A locked owner account shares nothing.');
        $this->db->update('nx_users', ['status' => 'active'], ['id' => $this->owner->id]);

        $rowId = (int) $this->db->fetchValue('SELECT id FROM nx_account_members WHERE member_id = ?', [$this->member->id]);
        self::assertSame(404, $this->post('/account/team/' . $rowId . '/remove')->status(), 'Only the owner removes members.');
        $this->actingAs($this->owner);
        $this->post('/account/team/' . $rowId . '/remove');
        $this->actingAs($this->member);
        self::assertSame(404, $this->get('/services/' . $this->server)->status());
        self::assertSame('revoked', $this->db->fetchValue('SELECT status FROM nx_account_members WHERE id = ?', [$rowId]), 'Kept for traceability.');
    }

    public function testInvitationsAreBoundToTheInvitedAddress(): void
    {
        $this->actingAs($this->owner);
        $this->post('/account/team', ['email' => $this->member->email, 'permissions' => ['services.view'], 'all_services' => '1']);
        preg_match('#/team/invitations/([a-f0-9]{64})#', (string) $this->lastMail('team_invitation')['html'], $match);

        $stranger = $this->createUser();
        $this->actingAs($stranger);
        $this->post('/team/invitations/' . $match[1] . '/accept');
        self::assertSame('invited', $this->db->fetchValue('SELECT status FROM nx_account_members WHERE owner_id = ?', [$this->owner->id]));
        self::assertSame(404, $this->get('/services/' . $this->server)->status());

        // Expired after seven days.
        $this->clock()->freeze(new DateTimeImmutable('2026-07-03 12:00:00'));
        $this->actingAs($this->member);
        self::assertSame(404, $this->get('/team/invitations/' . $match[1])->status());
        $this->post('/team/invitations/' . $match[1] . '/accept');
        self::assertSame('invited', $this->db->fetchValue('SELECT status FROM nx_account_members WHERE owner_id = ?', [$this->owner->id]));
    }

    public function testInvitationRules(): void
    {
        $this->actingAs($this->owner);
        $this->post('/account/team', ['email' => $this->owner->email, 'permissions' => ['services.view'], 'all_services' => '1']);
        $this->post('/account/team', ['email' => 'nobody@example.test', 'permissions' => []]);
        $this->post('/account/team', ['email' => 'foreign@example.test', 'permissions' => ['services.view'], 'services' => ['999999']]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_account_members'), 'Self, no permission and foreign services are refused.');

        $this->post('/account/team', ['email' => 'a@example.test', 'permissions' => ['services.view', 'admin.everything'], 'all_services' => '1']);
        $this->post('/account/team', ['email' => 'A@example.test', 'permissions' => ['services.view'], 'all_services' => '1']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_account_members'), 'No duplicate invitations.');
        self::assertSame('services.view', $this->db->fetchValue('SELECT permissions FROM nx_account_members'), 'Unknown permissions are dropped.');
    }

    public function testBillingPermissionAllowsPayingButNotWithOwnCredit(): void
    {
        $invoiceId = $this->db->insert('nx_invoices', [
            'number' => 'INV-TEAM-1', 'user_id' => $this->owner->id, 'type' => 'renewal', 'status' => 'unpaid', 'currency' => 'EUR',
            'subtotal' => 500, 'tax' => 95, 'total' => 595, 'due_at' => '2026-07-01 00:00:00', 'created_at' => '2026-06-25 12:00:00', 'updated_at' => '2026-06-25 12:00:00',
        ]);
        $this->join(['permissions' => ['services.view'], 'all_services' => '1']);
        self::assertSame(404, $this->get('/invoices/' . $invoiceId)->status(), 'No billing permission.');

        $rowId = (int) $this->db->fetchValue('SELECT id FROM nx_account_members WHERE member_id = ?', [$this->member->id]);
        $this->actingAs($this->owner);
        $this->post('/account/team/' . $rowId . '/update', ['permissions' => ['services.view', 'billing'], 'all_services' => '1']);
        $this->app->container()->get(\Nebrix\Settings\SettingsRepository::class)->set('payments.credit.enabled', true);
        $this->app->container()->get(\Nebrix\Billing\CreditService::class)->adjust($this->member->id, 10000, 'test');

        $this->actingAs($this->member);
        self::assertSame(200, $this->get('/invoices/' . $invoiceId)->status());
        self::assertStringContainsString('INV-TEAM-1', $this->get('/invoices')->body());
        $this->post('/invoices/' . $invoiceId . '/pay', ['gateway' => 'credit']);
        self::assertSame('unpaid', $this->db->fetchValue('SELECT status FROM nx_invoices WHERE id = ?', [$invoiceId]), 'A member cannot spend their own credit on another account.');
        self::assertSame(10000, $this->app->container()->get(\Nebrix\Billing\CreditService::class)->balance($this->member->id));
    }

    public function testMembersCanLeave(): void
    {
        $this->join(['permissions' => ['services.view'], 'all_services' => '1']);
        $rowId = (int) $this->db->fetchValue('SELECT id FROM nx_account_members WHERE member_id = ?', [$this->member->id]);
        self::assertStringContainsString('ACME GmbH', $this->get('/account/team')->body());
        $this->post('/account/team/memberships/' . $rowId . '/leave');
        self::assertSame(404, $this->get('/services/' . $this->server)->status());
    }
}
