<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Audit\AuditLogger;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Marketing consents of customers (currently: review invitations by
 * e-mail, which are advertising under § 7 UWG and need explicit consent).
 *
 * A consent records when, where (registration, checkout, account) and
 * from which network (truncated IP address) it was given and the text the
 * customer agreed to. It can be withdrawn at any time (account settings,
 * link in the e-mail); changes are audit-logged.
 */
class Consents
{
    public const REVIEW_INVITATION = 'review_invitation';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    public function has(int $userId, string $purpose): bool
    {
        return $this->db->fetchValue(
            'SELECT `id` FROM `nx_marketing_consents` WHERE `user_id` = ? AND `purpose` = ? AND `granted_at` IS NOT NULL AND `withdrawn_at` IS NULL',
            [$userId, $purpose],
        ) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, string $purpose): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_marketing_consents` WHERE `user_id` = ? AND `purpose` = ?', [$userId, $purpose]);
    }

    public function grant(int $userId, string $purpose, string $source, string $ip, string $text): void
    {
        $now = $this->clock->nowString();
        $values = [$now, $source, IpAddress::truncate($ip), mb_substr($text, 0, 500), $now];
        $this->db->run(
            'INSERT INTO `nx_marketing_consents` (`user_id`, `purpose`, `granted_at`, `granted_source`, `granted_ip`, `consent_text`, `withdrawn_at`, `withdrawn_via`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?)
             ON DUPLICATE KEY UPDATE `granted_at` = ?, `granted_source` = ?, `granted_ip` = ?, `consent_text` = ?, `withdrawn_at` = NULL, `withdrawn_via` = NULL, `updated_at` = ?',
            [$userId, $purpose, ...$values, ...$values],
        );
        $this->audit->log($userId, 'consent.granted', 'user', $userId, ['purpose' => $purpose, 'source' => $source], IpAddress::truncate($ip));
    }

    /** Withdraws a consent; returns false when there was none to withdraw. */
    public function withdraw(int $userId, string $purpose, string $via, ?string $ip): bool
    {
        $now = $this->clock->nowString();
        $changed = $this->db->run(
            'UPDATE `nx_marketing_consents` SET `withdrawn_at` = ?, `withdrawn_via` = ?, `updated_at` = ? WHERE `user_id` = ? AND `purpose` = ? AND `granted_at` IS NOT NULL AND `withdrawn_at` IS NULL',
            [$now, $via, $now, $userId, $purpose],
        )->rowCount() > 0;
        if ($changed) {
            $this->audit->log($userId, 'consent.withdrawn', 'user', $userId, ['purpose' => $purpose, 'via' => $via], $ip !== null ? IpAddress::truncate($ip) : null);
        }

        return $changed;
    }
}
