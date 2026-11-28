<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use PDOException;

/**
 * Satisfaction ratings (1–5 with an optional comment) for closed tickets:
 * once per ticket (primary key), only by the ticket owner (checked by the
 * caller with a user-scoped lookup).
 */
class TicketRatings
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forTicket(int $ticketId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_ticket_ratings` WHERE `ticket_id` = ?', [$ticketId]);
    }

    /** Stores the rating; false if the ticket was rated before. */
    public function rate(int $ticketId, int $userId, int $rating, ?string $comment): bool
    {
        try {
            $this->db->insert('nx_ticket_ratings', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'rating' => max(1, min(5, $rating)),
                'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000) : null,
                'created_at' => $this->clock->nowString(),
            ]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    /**
     * Average and count overall and for the last 30 days.
     *
     * @return array{average: float|null, count: int, average_30: float|null, count_30: int}
     */
    public function summary(): array
    {
        $all = $this->db->fetchOne('SELECT AVG(`rating`) AS a, COUNT(*) AS c FROM `nx_ticket_ratings`') ?? [];
        $recent = $this->db->fetchOne('SELECT AVG(`rating`) AS a, COUNT(*) AS c FROM `nx_ticket_ratings` WHERE `created_at` >= ?', [$this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')]) ?? [];

        return [
            'average' => ($all['a'] ?? null) !== null ? round((float) $all['a'], 1) : null,
            'count' => (int) ($all['c'] ?? 0),
            'average_30' => ($recent['a'] ?? null) !== null ? round((float) $recent['a'], 1) : null,
            'count_30' => (int) ($recent['c'] ?? 0),
        ];
    }
}
