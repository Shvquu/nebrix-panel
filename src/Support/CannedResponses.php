<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Prepared answers staff insert into ticket replies. Placeholders:
 * {customer_name}, {ticket_id}, {ticket_subject}, {staff_name}.
 * They are replaced as plain text (the reply is stored and shown escaped).
 */
class CannedResponses
{
    public const PLACEHOLDERS = ['{customer_name}', '{ticket_id}', '{ticket_subject}', '{staff_name}'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM `nx_canned_responses` ORDER BY `sort_order`, `title` LIMIT 500');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_canned_responses` WHERE `id` = ?', [$id]);
    }

    /**
     * @param array{title: string, body: string, sort_order: int} $data
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $now = $this->clock->nowString();
        $row = ['title' => mb_substr($data['title'], 0, 120), 'body' => $data['body'], 'sort_order' => $data['sort_order'], 'updated_by' => $actorId, 'updated_at' => $now];
        if ($id === null) {
            return $this->db->insert('nx_canned_responses', $row + ['created_by' => $actorId, 'created_at' => $now]);
        }
        $this->db->update('nx_canned_responses', $row, ['id' => $id]);

        return $id;
    }

    public function delete(int $id): bool
    {
        return $this->db->delete('nx_canned_responses', ['id' => $id]) > 0;
    }

    /**
     * Replaces the placeholders for one ticket.
     *
     * @param array<string, mixed> $ticket row of TicketRepository::find()
     */
    public static function fill(string $text, array $ticket, string $staffName): string
    {
        $customer = trim((string) ($ticket['first_name'] ?? '') . ' ' . (string) ($ticket['last_name'] ?? ''));

        return strtr($text, [
            '{customer_name}' => $customer !== '' ? $customer : (string) ($ticket['email'] ?? ''),
            '{ticket_id}' => (string) ($ticket['id'] ?? ''),
            '{ticket_subject}' => (string) ($ticket['subject'] ?? ''),
            '{staff_name}' => $staffName,
        ]);
    }
}
