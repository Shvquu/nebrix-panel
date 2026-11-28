<?php

declare(strict_types=1);

namespace Nebrix\Payment;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Saved payment methods of customers (stored at the payment provider; only
 * the reference and display details such as brand and last four digits are
 * kept here – never card numbers or IBANs).
 *
 * Exactly one active method per customer is the default used for automatic
 * renewal payments.
 */
final class PaymentMethodRepository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_REMOVED = 'removed';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Methods the customer still sees (active and inactive ones).
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `nx_payment_methods` WHERE `user_id` = ? AND `status` <> 'removed' ORDER BY `is_default` DESC, `id` DESC",
            [$userId],
        );
    }

    /** @return array<string, mixed>|null a method of this customer that is not removed */
    public function find(int $userId, int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `nx_payment_methods` WHERE `id` = ? AND `user_id` = ? AND `status` <> 'removed'",
            [$id, $userId],
        );
    }

    /** @return array<string, mixed>|null */
    public function defaultFor(int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `nx_payment_methods` WHERE `user_id` = ? AND `status` = 'active' AND `is_default` = 1 ORDER BY `id` DESC LIMIT 1",
            [$userId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByExternal(string $gateway, string $externalId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_payment_methods` WHERE `gateway` = ? AND `external_id` = ?', [$gateway, $externalId]);
    }

    /**
     * Stores (or refreshes) a method confirmed by the provider. The first
     * active method becomes the default.
     *
     * @param array{type: string, brand?: string|null, last4?: string|null, exp_month?: int|null, exp_year?: int|null, country?: string|null, mandate_id?: string|null, mandate_reference?: string|null} $details
     *
     * @return int|null the method ID, or null when the provider reference belongs to another customer or was removed
     */
    public function save(int $userId, string $gateway, string $externalId, string $customerRef, array $details): ?int
    {
        return $this->db->transaction(function () use ($userId, $gateway, $externalId, $customerRef, $details): ?int {
            // Serializes concurrent saves of one customer (webhook and return page) so there is one default.
            $this->db->fetchAll('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$userId]);
            $now = $this->clock->nowString();
            $fields = [
                'type' => $details['type'],
                'brand' => isset($details['brand']) ? mb_substr((string) $details['brand'], 0, 30) : null,
                'last4' => isset($details['last4']) && preg_match('/^\d{4}$/', (string) $details['last4']) === 1 ? (string) $details['last4'] : null,
                'exp_month' => isset($details['exp_month']) ? (int) $details['exp_month'] : null,
                'exp_year' => isset($details['exp_year']) ? (int) $details['exp_year'] : null,
                'country' => isset($details['country']) && preg_match('/^[A-Z]{2}$/', strtoupper((string) $details['country'])) === 1 ? strtoupper((string) $details['country']) : null,
                'mandate_id' => isset($details['mandate_id']) ? mb_substr((string) $details['mandate_id'], 0, 64) : null,
                'mandate_reference' => isset($details['mandate_reference']) ? mb_substr((string) $details['mandate_reference'], 0, 64) : null,
                'updated_at' => $now,
            ];

            $existing = $this->findByExternal($gateway, $externalId);
            if ($existing !== null) {
                if ((int) $existing['user_id'] !== $userId || $existing['status'] === self::STATUS_REMOVED) {
                    return null;
                }
                $this->db->update('nx_payment_methods', $fields, ['id' => (int) $existing['id']]);

                return (int) $existing['id'];
            }

            return $this->db->insert('nx_payment_methods', $fields + [
                'user_id' => $userId,
                'gateway' => $gateway,
                'external_id' => $externalId,
                'customer_ref' => $customerRef,
                'is_default' => $this->defaultFor($userId) === null ? 1 : 0,
                'status' => self::STATUS_ACTIVE,
                'created_at' => $now,
            ]);
        });
    }

    public function makeDefault(int $userId, int $id): bool
    {
        return $this->db->transaction(function () use ($userId, $id): bool {
            $this->db->fetchAll('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$userId]);
            $method = $this->find($userId, $id);
            if ($method === null || $method['status'] !== self::STATUS_ACTIVE) {
                return false;
            }
            $this->db->run('UPDATE `nx_payment_methods` SET `is_default` = 0 WHERE `user_id` = ?', [$userId]);
            $this->db->update('nx_payment_methods', ['is_default' => 1, 'updated_at' => $this->clock->nowString()], ['id' => $id]);

            return true;
        });
    }

    /** Removed (detached at the provider): never used again, hidden from the customer. */
    public function markRemoved(int $id): void
    {
        $this->changeStatus($id, self::STATUS_REMOVED);
    }

    public function markRemovedByExternal(string $gateway, string $externalId): void
    {
        $method = $this->findByExternal($gateway, $externalId);
        if ($method !== null && $method['status'] !== self::STATUS_REMOVED) {
            $this->changeStatus((int) $method['id'], self::STATUS_REMOVED);
        }
    }

    /** A revoked or failed SEPA mandate: the method stays visible but is no longer charged. */
    public function markMandateInactive(string $mandateId): void
    {
        foreach ($this->db->fetchAll("SELECT `id` FROM `nx_payment_methods` WHERE `mandate_id` = ? AND `status` = 'active'", [$mandateId]) as $row) {
            $this->changeStatus((int) $row['id'], self::STATUS_INACTIVE);
        }
    }

    private function changeStatus(int $id, string $status): void
    {
        $this->db->transaction(function () use ($id, $status): void {
            $method = $this->db->fetchOne('SELECT * FROM `nx_payment_methods` WHERE `id` = ? FOR UPDATE', [$id]);
            if ($method === null) {
                return;
            }
            $now = $this->clock->nowString();
            $this->db->update('nx_payment_methods', [
                'status' => $status,
                'is_default' => 0,
                'updated_at' => $now,
                'removed_at' => $status === self::STATUS_REMOVED ? $now : $method['removed_at'],
            ], ['id' => $id]);

            // The newest remaining active method takes over as the default.
            if ((int) $method['is_default'] === 1) {
                $next = $this->db->fetchValue(
                    "SELECT `id` FROM `nx_payment_methods` WHERE `user_id` = ? AND `status` = 'active' ORDER BY `id` DESC LIMIT 1",
                    [(int) $method['user_id']],
                );
                if ($next !== null) {
                    $this->db->update('nx_payment_methods', ['is_default' => 1], ['id' => (int) $next]);
                }
            }
        });
    }
}
