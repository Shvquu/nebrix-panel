<?php

declare(strict_types=1);

namespace Nebrix\Account;

use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;

/**
 * Team access: an account owner invites other users with limited
 * permissions on all or selected services of the account.
 *
 * Permissions: services.view (see the service and its status),
 * services.manage (power actions, reinstall, console, backups),
 * services.credentials (show access data), billing (view and pay invoices).
 * Cancellations, package changes, orders, payment methods, account settings,
 * API tokens and the team itself always stay with the owner.
 *
 * Every lookup checks the membership server-side and requires an active
 * owner account; a revoked membership takes effect with the next request.
 */
final class TeamService
{
    public const PERMISSIONS = [
        'services.view' => 'View services and their status',
        'services.manage' => 'Manage services (start, stop, reinstall, console, backups)',
        'services.credentials' => 'Show access data of services',
        'billing' => 'View and pay invoices',
    ];
    public const MAX_MEMBERS = 10;
    private const TOKEN_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly ServiceRepository $services,
        private readonly InvoiceRepository $invoices,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    // ------------------------------------------------------------ owner side

    /** @return list<array<string, mixed>> members and open invitations of an account */
    public function members(int $ownerId): array
    {
        return array_map([$this, 'decode'], $this->db->fetchAll(
            "SELECT m.*, u.`first_name`, u.`last_name`, u.`email` AS member_email FROM `nx_account_members` m
             LEFT JOIN `nx_users` u ON u.`id` = m.`member_id`
             WHERE m.`owner_id` = ? AND m.`status` IN ('invited', 'active') ORDER BY m.`id`",
            [$ownerId],
        ));
    }

    /**
     * Invites a user by e-mail and sends the invitation link.
     *
     * @param list<string> $permissions
     * @param list<int> $serviceIds only used when $allServices is false
     *
     * @throws TeamException
     */
    public function invite(User $owner, string $email, array $permissions, bool $allServices, array $serviceIds): int
    {
        $email = mb_strtolower(trim($email));
        if (strcasecmp($email, $owner->email) === 0) {
            throw new TeamException('You cannot invite yourself.');
        }
        [$permissions, $serviceIds] = $this->normalize($owner->id, $permissions, $allServices, $serviceIds);

        return $this->db->transaction(function () use ($owner, $email, $permissions, $allServices, $serviceIds): int {
            $this->db->fetchAll('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$owner->id]);
            $open = $this->db->fetchAll("SELECT m.`email`, u.`email` AS member_email FROM `nx_account_members` m LEFT JOIN `nx_users` u ON u.`id` = m.`member_id`
                WHERE m.`owner_id` = ? AND m.`status` IN ('invited', 'active')", [$owner->id]);
            if (count($open) >= self::MAX_MEMBERS) {
                throw new TeamException('An account can have at most ten team members.');
            }
            foreach ($open as $row) {
                if (mb_strtolower((string) $row['email']) === $email || mb_strtolower((string) ($row['member_email'] ?? '')) === $email) {
                    throw new TeamException('This address has already been invited.');
                }
            }

            $token = bin2hex(random_bytes(32));
            $now = $this->clock->now();
            $id = $this->db->insert('nx_account_members', [
                'owner_id' => $owner->id,
                'email' => $email,
                'permissions' => implode(',', $permissions),
                'all_services' => $allServices ? 1 : 0,
                'service_ids' => $allServices ? null : implode(',', $serviceIds),
                'status' => 'invited',
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => $now->modify('+' . self::TOKEN_DAYS . ' days')->format('Y-m-d H:i:s'),
                'invited_by' => $owner->id,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $this->mailer->queue($email, null, 'team_invitation', [
                'owner_name' => $owner->displayName(),
                'invitation_url' => rtrim($this->config->string('app.url'), '/') . '/team/invitations/' . $token,
                'expires_days' => (string) self::TOKEN_DAYS,
            ], (string) ($owner->attribute('language') ?? 'en') ?: 'en');

            return $id;
        });
    }

    /**
     * @param list<string> $permissions
     * @param list<int> $serviceIds
     *
     * @throws TeamException
     */
    public function update(int $ownerId, int $memberRowId, array $permissions, bool $allServices, array $serviceIds): void
    {
        $row = $this->ownedRow($ownerId, $memberRowId);
        [$permissions, $serviceIds] = $this->normalize($ownerId, $permissions, $allServices, $serviceIds);
        $this->db->update('nx_account_members', [
            'permissions' => implode(',', $permissions),
            'all_services' => $allServices ? 1 : 0,
            'service_ids' => $allServices ? null : implode(',', $serviceIds),
            'updated_at' => $this->clock->nowString(),
        ], ['id' => (int) $row['id']]);
    }

    /** Removes a member or withdraws an invitation (kept as "revoked"). */
    public function revoke(int $ownerId, int $memberRowId): void
    {
        $row = $this->ownedRow($ownerId, $memberRowId);
        $this->markRevoked((int) $row['id']);
    }

    // ----------------------------------------------------------- member side

    /** @return array<string, mixed>|null an open, unexpired invitation */
    public function invitation(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT m.*, u.`first_name`, u.`last_name`, u.`company`, u.`email` AS owner_email FROM `nx_account_members` m
             JOIN `nx_users` u ON u.`id` = m.`owner_id`
             WHERE m.`token_hash` = ? AND m.`status` = 'invited' AND m.`token_expires_at` > ?",
            [hash('sha256', $token), $this->clock->nowString()],
        );

        return $row === null ? null : $this->decode($row);
    }

    /**
     * Accepts an invitation for the signed-in user. The invitation is bound
     * to the invited e-mail address.
     *
     * @throws TeamException
     */
    public function accept(string $token, User $user): int
    {
        return $this->db->transaction(function () use ($token, $user): int {
            $invitation = $this->invitation($token);
            if ($invitation === null) {
                throw new TeamException('This invitation is invalid or has expired.');
            }
            $row = $this->db->fetchOne('SELECT * FROM `nx_account_members` WHERE `id` = ? FOR UPDATE', [(int) $invitation['id']]);
            if ($row === null || $row['status'] !== 'invited') {
                throw new TeamException('This invitation is invalid or has expired.');
            }
            if (strcasecmp((string) $row['email'], $user->email) !== 0) {
                throw new TeamException('This invitation was sent to another e-mail address. Please sign in with that address.');
            }
            if ((int) $row['owner_id'] === $user->id) {
                throw new TeamException('You cannot invite yourself.');
            }
            if ($this->db->fetchValue("SELECT COUNT(*) FROM `nx_account_members` WHERE `owner_id` = ? AND `member_id` = ? AND `status` = 'active'", [(int) $row['owner_id'], $user->id]) > 0) {
                throw new TeamException('You are already a member of this account.');
            }
            $now = $this->clock->nowString();
            $this->db->update('nx_account_members', [
                'member_id' => $user->id,
                'status' => 'active',
                'token_hash' => null,
                'token_expires_at' => null,
                'accepted_at' => $now,
                'updated_at' => $now,
            ], ['id' => (int) $row['id']]);

            return (int) $row['owner_id'];
        });
    }

    /** @return list<array<string, mixed>> accounts the user is a member of */
    public function memberships(int $memberId): array
    {
        return array_map([$this, 'decode'], $this->db->fetchAll(
            "SELECT m.*, u.`first_name`, u.`last_name`, u.`company`, u.`email` AS owner_email FROM `nx_account_members` m
             JOIN `nx_users` u ON u.`id` = m.`owner_id`
             WHERE m.`member_id` = ? AND m.`status` = 'active' AND u.`status` = 'active' ORDER BY m.`id`",
            [$memberId],
        ));
    }

    public function leave(int $memberId, int $memberRowId): void
    {
        $row = $this->db->fetchOne("SELECT * FROM `nx_account_members` WHERE `id` = ? AND `member_id` = ? AND `status` = 'active'", [$memberRowId, $memberId]);
        if ($row === null) {
            throw new TeamException('Membership not found.');
        }
        $this->markRevoked((int) $row['id']);
    }

    // -------------------------------------------------------- access checks

    /**
     * A service the user may access with the given permission: their own
     * (any permission) or one shared with them.
     */
    public function service(int $serviceId, User $user, string $permission): ?Service
    {
        $own = $this->services->findForUser($serviceId, $user->id);
        if ($own !== null) {
            return $own;
        }
        $service = $this->services->find($serviceId);
        if ($service === null) {
            return null;
        }
        $membership = $this->membership($service->userId, $user->id);

        return $membership !== null && $this->covers($membership, $service->id) && in_array($permission, $membership['permission_list'], true)
            ? $service
            : null;
    }

    /**
     * Permissions of the user for a service shared with them ([] for own services and no access).
     *
     * @return list<string>
     */
    public function sharedPermissions(Service $service, User $user): array
    {
        if ($service->userId === $user->id) {
            return [];
        }
        $membership = $this->membership($service->userId, $user->id);

        return $membership !== null && $this->covers($membership, $service->id) ? $membership['permission_list'] : [];
    }

    /**
     * Services shared with the user, grouped with the owner's name.
     *
     * @return list<array{service: Service, owner: string, permissions: list<string>}>
     */
    public function sharedServices(User $user): array
    {
        $shared = [];
        foreach ($this->memberships($user->id) as $membership) {
            if (!in_array('services.view', $membership['permission_list'], true)) {
                continue;
            }
            foreach ($this->services->forUser((int) $membership['owner_id']) as $service) {
                if ($this->covers($membership, $service->id)) {
                    $shared[] = ['service' => $service, 'owner' => self::ownerName($membership), 'permissions' => $membership['permission_list']];
                }
            }
        }

        return $shared;
    }

    /** @return array<string, mixed>|null an own invoice or one of an account with billing permission */
    public function invoice(int $invoiceId, User $user): ?array
    {
        $own = $this->invoices->findForUser($invoiceId, $user->id);
        if ($own !== null) {
            return $own;
        }
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            return null;
        }
        $membership = $this->membership((int) $invoice['user_id'], $user->id);

        return $membership !== null && in_array('billing', $membership['permission_list'], true) ? $invoice : null;
    }

    /**
     * Invoices of accounts the user may pay for.
     *
     * @return list<array<string, mixed>>
     */
    public function sharedInvoices(User $user): array
    {
        $invoices = [];
        foreach ($this->memberships($user->id) as $membership) {
            if (in_array('billing', $membership['permission_list'], true)) {
                foreach ($this->invoices->forUser((int) $membership['owner_id'], 50) as $invoice) {
                    $invoices[] = $invoice + ['owner_name' => self::ownerName($membership)];
                }
            }
        }

        return $invoices;
    }

    /** @param array<string, mixed> $row */
    public static function ownerName(array $row): string
    {
        $name = trim((string) ($row['company'] ?? '')) !== '' ? (string) $row['company'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        return $name !== '' ? $name : (string) ($row['owner_email'] ?? '');
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string, mixed>|null active membership of $memberId in the active account $ownerId */
    private function membership(int $ownerId, int $memberId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT m.* FROM `nx_account_members` m JOIN `nx_users` u ON u.`id` = m.`owner_id`
             WHERE m.`owner_id` = ? AND m.`member_id` = ? AND m.`status` = 'active' AND u.`status` = 'active' LIMIT 1",
            [$ownerId, $memberId],
        );

        return $row === null ? null : $this->decode($row);
    }

    /** @param array<string, mixed> $membership */
    private function covers(array $membership, int $serviceId): bool
    {
        return (int) $membership['all_services'] === 1 || in_array($serviceId, $membership['service_list'], true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $row['permission_list'] = array_values(array_intersect(array_keys(self::PERMISSIONS), explode(',', (string) $row['permissions'])));
        $row['service_list'] = array_values(array_filter(array_map('intval', explode(',', (string) ($row['service_ids'] ?? ''))), static fn (int $id): bool => $id > 0));

        return $row;
    }

    /**
     * @param list<string> $permissions
     * @param list<int> $serviceIds
     * @return array{0: list<string>, 1: list<int>}
     */
    private function normalize(int $ownerId, array $permissions, bool $allServices, array $serviceIds): array
    {
        $permissions = array_values(array_intersect(array_keys(self::PERMISSIONS), $permissions));
        // Managing or seeing access data implies seeing the service.
        if ((in_array('services.manage', $permissions, true) || in_array('services.credentials', $permissions, true)) && !in_array('services.view', $permissions, true)) {
            $permissions[] = 'services.view';
        }
        if ($permissions === []) {
            throw new TeamException('Please choose at least one permission.');
        }
        $ids = [];
        if (!$allServices) {
            $own = array_map(static fn (Service $s): int => $s->id, $this->services->forUser($ownerId));
            // Only services of the owner's account can be shared.
            $ids = array_values(array_unique(array_intersect(array_map('intval', $serviceIds), $own)));
            if ($ids === [] && in_array('services.view', $permissions, true)) {
                throw new TeamException('Please choose at least one service.');
            }
        }

        return [$permissions, $ids];
    }

    /** @return array<string, mixed> */
    private function ownedRow(int $ownerId, int $memberRowId): array
    {
        return $this->db->fetchOne("SELECT * FROM `nx_account_members` WHERE `id` = ? AND `owner_id` = ? AND `status` IN ('invited', 'active')", [$memberRowId, $ownerId])
            ?? throw new TeamException('Team member not found.');
    }

    private function markRevoked(int $id): void
    {
        $now = $this->clock->nowString();
        $this->db->update('nx_account_members', ['status' => 'revoked', 'token_hash' => null, 'token_expires_at' => null, 'revoked_at' => $now, 'updated_at' => $now], ['id' => $id]);
    }
}
