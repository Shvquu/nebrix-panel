<?php

declare(strict_types=1);

namespace Nebrix\Status;

use Nebrix\Audit\AuditLogger;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\View\Translator;

/**
 * Public status page: components with their state, incidents and
 * maintenance announcements with a timeline of updates.
 *
 * The state of a component is the worst of: the state set by staff, an
 * ongoing maintenance or published incident that affects it, and a failed
 * health check of a linked integration.
 */
class StatusService
{
    public const OPERATIONAL = 'operational';
    public const MAINTENANCE = 'maintenance';
    public const DEGRADED = 'degraded';
    public const PARTIAL_OUTAGE = 'partial_outage';
    public const MAJOR_OUTAGE = 'major_outage';

    /** Component states, from best to worst. */
    public const COMPONENT_STATES = [self::OPERATIONAL, self::MAINTENANCE, self::DEGRADED, self::PARTIAL_OUTAGE, self::MAJOR_OUTAGE];

    public const INCIDENT = 'incident';
    public const PLANNED = 'maintenance';

    public const INCIDENT_STATES = ['investigating', 'identified', 'monitoring', 'resolved'];
    public const MAINTENANCE_STATES = ['scheduled', 'in_progress', 'completed'];
    public const IMPACTS = ['none', 'minor', 'major', 'critical'];

    private const IMPACT_STATE = ['none' => self::OPERATIONAL, 'minor' => self::DEGRADED, 'major' => self::PARTIAL_OUTAGE, 'critical' => self::MAJOR_OUTAGE];

    /** Upcoming maintenance is announced in the customer area this many hours ahead. */
    private const BANNER_HOURS = 72;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Translator $translator,
        private readonly Config $config,
        private readonly \Nebrix\Settings\SettingsRepository $settings,
    ) {
    }

    /** UTC database time in the panel's time zone, e.g. "28.11.2026 22:00 CET". */
    public function localTime(string $utc, string $locale): string
    {
        try {
            $zone = new \DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        $time = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone);

        return $time->format($locale === 'de' ? 'd.m.Y H:i T' : 'Y-m-d H:i T');
    }

    /** A date and time entered in the panel's time zone (form field) as UTC database time. */
    public function toUtc(string $local): ?string
    {
        try {
            $zone = new \DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
            $time = new \DateTimeImmutable($local, $zone);
        } catch (\Exception) {
            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC database time as value of a datetime-local field in the panel's time zone. */
    public function toLocalInput(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        try {
            $zone = new \DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\\TH:i');
    }

    /**
     * Everything the public page shows.
     *
     * @return array{state: string, groups: array<string, list<array<string, mixed>>>, active: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        $now = $this->clock->nowString();
        $incidents = $this->incidents('i.`is_published` = 1 AND (i.`resolved_at` IS NULL OR i.`resolved_at` >= ?)', [$this->clock->now()->modify('-14 days')->format('Y-m-d H:i:s')]);
        $active = [];
        $upcoming = [];
        $history = [];
        foreach ($incidents as $incident) {
            if ($incident['resolved_at'] !== null) {
                $history[] = $incident;
            } elseif ($incident['type'] === self::PLANNED && $incident['status'] === 'scheduled' && $incident['starts_at'] > $now) {
                $upcoming[] = $incident;
            } else {
                $active[] = $incident;
            }
        }
        usort($upcoming, static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        $components = $this->components(true, $active);
        $groups = [];
        $state = self::OPERATIONAL;
        foreach ($components as $component) {
            $groups[(string) ($component['group_name'] ?? '')][] = $component;
            $state = self::worse($state, (string) $component['effective_status']);
        }
        foreach ($active as $incident) {
            $state = self::worse($state, $incident['type'] === self::PLANNED ? self::MAINTENANCE : self::IMPACT_STATE[(string) $incident['impact']] ?? self::DEGRADED);
        }

        return ['state' => $state, 'groups' => $groups, 'active' => $active, 'upcoming' => $upcoming, 'history' => $history];
    }

    /**
     * Current incidents and maintenance within the next days, for the
     * notice in the customer area.
     *
     * @return list<array<string, mixed>>
     */
    public function banner(): array
    {
        $until = $this->clock->now()->modify('+' . self::BANNER_HOURS . ' hours')->format('Y-m-d H:i:s');

        return $this->db->fetchAll(
            "SELECT `id`, `type`, `title`, `impact`, `status`, `starts_at`, `ends_at` FROM `nx_status_incidents`
              WHERE `is_published` = 1 AND `resolved_at` IS NULL AND `starts_at` <= ? ORDER BY (`type` = 'incident') DESC, `starts_at` LIMIT 3",
            [$until],
        );
    }

    /**
     * Components with their effective state.
     *
     * @param list<array<string, mixed>>|null $active published, unresolved incidents (loaded when null)
     * @return list<array<string, mixed>>
     */
    public function components(bool $visibleOnly, ?array $active = null): array
    {
        $active ??= $this->incidents('i.`is_published` = 1 AND i.`resolved_at` IS NULL', []);
        $now = $this->clock->nowString();
        $rows = $this->db->fetchAll(
            'SELECT c.*, g.`name` AS integration_name, g.`health_status`, g.`is_enabled` AS integration_enabled
               FROM `nx_status_components` c LEFT JOIN `nx_integrations` g ON g.`id` = c.`integration_id`'
            . ($visibleOnly ? ' WHERE c.`is_visible` = 1' : '') . ' ORDER BY c.`sort_order`, c.`name`',
        );
        foreach ($rows as &$row) {
            $state = in_array($row['status'], self::COMPONENT_STATES, true) ? (string) $row['status'] : self::OPERATIONAL;
            if ($row['integration_id'] !== null && (bool) $row['integration_enabled'] && in_array($row['health_status'], ['degraded', 'down'], true)) {
                $state = self::worse($state, self::DEGRADED);
            }
            foreach ($active as $incident) {
                if (!in_array((int) $row['id'], $incident['component_ids'], true)) {
                    continue;
                }
                if ($incident['type'] === self::PLANNED) {
                    // Scheduled maintenance only counts once it has started.
                    if ($incident['status'] === 'in_progress' || ($incident['status'] === 'scheduled' && $incident['starts_at'] <= $now)) {
                        $state = self::worse($state, self::MAINTENANCE);
                    }
                } else {
                    $state = self::worse($state, self::IMPACT_STATE[(string) $incident['impact']] ?? self::DEGRADED);
                }
            }
            $row['effective_status'] = $state;
            unset($row['health_status'], $row['integration_enabled']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findComponent(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_status_components` WHERE `id` = ?', [$id]);
    }

    /**
     * @param array{name: string, description: ?string, group_name: ?string, status: string, integration_id: ?int, is_visible: bool, sort_order: int} $data
     */
    public function saveComponent(?int $id, array $data, int $actorId, ?string $ip): int
    {
        $row = [
            'name' => mb_substr($data['name'], 0, 100),
            'description' => $data['description'] !== null && $data['description'] !== '' ? mb_substr($data['description'], 0, 255) : null,
            'group_name' => $data['group_name'] !== null && $data['group_name'] !== '' ? mb_substr($data['group_name'], 0, 60) : null,
            'status' => in_array($data['status'], self::COMPONENT_STATES, true) ? $data['status'] : self::OPERATIONAL,
            'integration_id' => $data['integration_id'],
            'is_visible' => $data['is_visible'] ? 1 : 0,
            'sort_order' => $data['sort_order'],
            'updated_at' => $this->clock->nowString(),
        ];
        if ($id === null) {
            $id = $this->db->insert('nx_status_components', $row + ['created_at' => $this->clock->nowString()]);
        } else {
            $this->db->update('nx_status_components', $row, ['id' => $id]);
        }
        $this->audit->log($actorId, 'status.component_saved', 'status_component', $id, ['status' => $row['status']], $ip);

        return $id;
    }

    public function deleteComponent(int $id, int $actorId, ?string $ip): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->run('DELETE FROM `nx_status_incident_components` WHERE `component_id` = ?', [$id]);
            $this->db->run('DELETE FROM `nx_status_components` WHERE `id` = ?', [$id]);
        });
        $this->audit->log($actorId, 'status.component_deleted', 'status_component', $id, [], $ip);
    }

    /**
     * Incidents and maintenance for the admin list (all, newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function allIncidents(): array
    {
        return $this->incidents('1 = 1', [], 200);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findIncident(int $id): ?array
    {
        return $this->incidents('i.`id` = ?', [$id], 1)[0] ?? null;
    }

    /**
     * Creates an incident or a maintenance announcement with its first message.
     *
     * @param array{type: string, title: string, impact: string, status: string, starts_at: string, ends_at: ?string, is_published: bool, component_ids: list<int>, message: string} $data
     */
    public function create(array $data, int $actorId, ?string $ip, bool $notify): int
    {
        $id = $this->db->transaction(function () use ($data, $actorId): int {
            $now = $this->clock->nowString();
            $id = $this->db->insert('nx_status_incidents', [
                'type' => $data['type'],
                'title' => mb_substr($data['title'], 0, 150),
                'impact' => $data['type'] === self::PLANNED ? 'none' : $data['impact'],
                'status' => $data['status'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'resolved_at' => self::isFinal($data['status']) ? $now : null,
                'is_published' => $data['is_published'] ? 1 : 0,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->linkComponents($id, $data['component_ids']);
            $this->db->insert('nx_status_updates', ['incident_id' => $id, 'status' => $data['status'], 'message' => $data['message'], 'created_by' => $actorId, 'created_at' => $now]);

            return $id;
        });
        $this->audit->log($actorId, 'status.incident_created', 'status_incident', $id, ['type' => $data['type'], 'published' => $data['is_published']], $ip);
        if ($notify && $data['is_published']) {
            $this->notify($id, $data['message']);
        }

        return $id;
    }

    /**
     * Changes title, times, impact, components and visibility.
     *
     * @param array{title: string, impact: string, starts_at: string, ends_at: ?string, is_published: bool, component_ids: list<int>} $data
     */
    public function update(int $id, array $data, int $actorId, ?string $ip): void
    {
        $incident = $this->findIncident($id) ?? throw new \RuntimeException('Not found.');
        $this->db->transaction(function () use ($id, $data, $incident): void {
            $this->db->update('nx_status_incidents', [
                'title' => mb_substr($data['title'], 0, 150),
                'impact' => $incident['type'] === self::PLANNED ? 'none' : $data['impact'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_published' => $data['is_published'] ? 1 : 0,
                'updated_at' => $this->clock->nowString(),
            ], ['id' => $id]);
            $this->db->run('DELETE FROM `nx_status_incident_components` WHERE `incident_id` = ?', [$id]);
            $this->linkComponents($id, $data['component_ids']);
        });
        $this->audit->log($actorId, 'status.incident_updated', 'status_incident', $id, [], $ip);
    }

    /**
     * Adds a timeline entry and moves the incident to that state.
     */
    public function addUpdate(int $id, string $status, string $message, ?int $actorId, ?string $ip, bool $notify): void
    {
        $incident = $this->findIncident($id) ?? throw new \RuntimeException('Not found.');
        $allowed = $incident['type'] === self::PLANNED ? self::MAINTENANCE_STATES : self::INCIDENT_STATES;
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        $now = $this->clock->nowString();
        $this->db->transaction(function () use ($id, $status, $message, $actorId, $now): void {
            $this->db->insert('nx_status_updates', ['incident_id' => $id, 'status' => $status, 'message' => $message, 'created_by' => $actorId, 'created_at' => $now]);
            $this->db->update('nx_status_incidents', [
                'status' => $status,
                'resolved_at' => self::isFinal($status) ? $now : null,
                'updated_at' => $now,
            ], ['id' => $id]);
        });
        $this->audit->log($actorId, 'status.update_posted', 'status_incident', $id, ['status' => $status], $ip);
        if ($notify && (bool) $incident['is_published']) {
            $this->notify($id, $message);
        }
    }

    public function delete(int $id, int $actorId, ?string $ip): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->run('DELETE FROM `nx_status_updates` WHERE `incident_id` = ?', [$id]);
            $this->db->run('DELETE FROM `nx_status_incident_components` WHERE `incident_id` = ?', [$id]);
            $this->db->run('DELETE FROM `nx_status_incidents` WHERE `id` = ?', [$id]);
        });
        $this->audit->log($actorId, 'status.incident_deleted', 'status_incident', $id, [], $ip);
    }

    /**
     * Scheduler: starts maintenance whose window has begun and completes it
     * when the planned end has passed.
     *
     * @return array{started: int, completed: int}
     */
    public function advanceMaintenance(): array
    {
        $now = $this->clock->nowString();
        $started = 0;
        $completed = 0;
        foreach ($this->db->fetchAll("SELECT `id` FROM `nx_status_incidents` WHERE `type` = 'maintenance' AND `status` = 'scheduled' AND `starts_at` <= ? AND (`ends_at` IS NULL OR `ends_at` > ?)", [$now, $now]) as $row) {
            $this->addUpdate((int) $row['id'], 'in_progress', 'The maintenance has started.', null, null, false);
            $started++;
        }
        foreach ($this->db->fetchAll("SELECT `id` FROM `nx_status_incidents` WHERE `type` = 'maintenance' AND `status` IN ('scheduled', 'in_progress') AND `ends_at` IS NOT NULL AND `ends_at` <= ?", [$now]) as $row) {
            $this->addUpdate((int) $row['id'], 'completed', 'The maintenance has been completed.', null, null, false);
            $completed++;
        }

        return ['started' => $started, 'completed' => $completed];
    }

    /**
     * E-mails the customers affected by an incident: customers with an
     * active service on an integration of the affected components; when no
     * affected component is linked to an integration, all customers with
     * an active service.
     */
    public function notify(int $id, string $message): int
    {
        $incident = $this->findIncident($id);
        if ($incident === null) {
            return 0;
        }
        $integrations = array_values(array_filter(array_map(
            'intval',
            array_column($this->db->fetchAll(
                'SELECT c.`integration_id` FROM `nx_status_incident_components` ic JOIN `nx_status_components` c ON c.`id` = ic.`component_id` WHERE ic.`incident_id` = ? AND c.`integration_id` IS NOT NULL',
                [$id],
            ), 'integration_id'),
        )));
        $sql = "SELECT DISTINCT u.`id`, u.`email`, u.`first_name`, u.`last_name`, u.`language` FROM `nx_users` u JOIN `nx_services` s ON s.`user_id` = u.`id`
                 WHERE u.`status` = 'active' AND s.`status` IN ('active', 'suspended')";
        if ($integrations !== []) {
            $sql .= ' AND s.`integration_id` IN (' . implode(',', $integrations) . ')';
        }
        $sent = 0;
        $url = rtrim($this->config->string('app.url'), '/') . '/status';
        foreach ($this->db->fetchAll($sql . ' LIMIT 20000') as $user) {
            $locale = (string) ($user['language'] ?? 'en');
            $name = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
            $window = $incident['type'] === self::PLANNED
                ? $this->localTime((string) $incident['starts_at'], $locale) . ($incident['ends_at'] !== null ? ' – ' . $this->localTime((string) $incident['ends_at'], $locale) : '')
                : '';
            $this->mailer->queue((string) $user['email'], $name !== '' ? $name : null, $incident['type'] === self::PLANNED ? 'status_maintenance' : 'status_incident', [
                'name' => $name !== '' ? $name : (string) $user['email'],
                'title' => (string) $incident['title'],
                'message' => $message,
                'window' => $window,
                'status' => $this->translator->translateTo($locale, ucfirst(str_replace('_', ' ', (string) $incident['status']))),
                'status_url' => $url,
            ], $locale);
            $sent++;
        }
        $this->db->update('nx_status_incidents', ['notified_at' => $this->clock->nowString()], ['id' => $id]);

        return $sent;
    }

    public static function worse(string $a, string $b): string
    {
        $rank = array_flip(self::COMPONENT_STATES);

        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, ['resolved', 'completed'], true);
    }

    /**
     * @param list<int> $componentIds
     */
    private function linkComponents(int $incidentId, array $componentIds): void
    {
        foreach (array_unique($componentIds) as $componentId) {
            if ($this->findComponent($componentId) !== null) {
                $this->db->insert('nx_status_incident_components', ['incident_id' => $incidentId, 'component_id' => $componentId]);
            }
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function incidents(string $where, array $params, int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.* FROM `nx_status_incidents` i WHERE ' . $where . ' ORDER BY i.`starts_at` DESC, i.`id` DESC LIMIT ' . max(1, $limit),
            $params,
        );
        if ($rows === []) {
            return [];
        }
        $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $components = [];
        foreach ($this->db->fetchAll('SELECT ic.`incident_id`, c.`id`, c.`name` FROM `nx_status_incident_components` ic JOIN `nx_status_components` c ON c.`id` = ic.`component_id` WHERE ic.`incident_id` IN (' . $ids . ') ORDER BY c.`sort_order`, c.`name`') as $link) {
            $components[(int) $link['incident_id']][] = ['id' => (int) $link['id'], 'name' => (string) $link['name']];
        }
        $updates = [];
        foreach ($this->db->fetchAll('SELECT `incident_id`, `status`, `message`, `created_at` FROM `nx_status_updates` WHERE `incident_id` IN (' . $ids . ') ORDER BY `created_at` DESC, `id` DESC') as $update) {
            $updates[(int) $update['incident_id']][] = $update;
        }
        foreach ($rows as &$row) {
            $row['components'] = $components[(int) $row['id']] ?? [];
            $row['component_ids'] = array_column($row['components'], 'id');
            $row['updates'] = $updates[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }
}
