<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Landing pages of the public website (e.g. /minecraft-server-mieten):
 * hero, introduction (Markdown), feature sections, FAQ and the packages of
 * one product page (optionally one product line). Only published pages are
 * public; staff preview drafts.
 */
class LandingPages
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    /**
     * Addresses that are never used for landing pages: areas of the
     * application, static directories of public/ and files search engines
     * expect. Every other address is also checked against the route table.
     */
    public const RESERVED = [
        'about', 'account', 'admin', 'api', 'assets', 'blog', 'branding', 'cancel-contract', 'dashboard', 'email', 'favicon',
        'health', 'index', 'invoices', 'login', 'logout', 'media', 'newsletter', 'orders', 'password', 'products', 'r', 'register',
        'reviews', 'robots', 'services', 'sitemap', 'status', 'store', 'tickets', 'transactions', 'two-factor', 'uploads',
        'webhooks', 'withdraw-contract',
    ];

    /** Pattern of a landing page address (also used by the route). */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

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
        return $this->db->fetchAll('SELECT * FROM `nx_landing_pages` ORDER BY `status` DESC, `slug` LIMIT 500');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_landing_pages` WHERE `id` = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublished(string $slug): ?array
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 100) {
            return null;
        }

        return $this->db->fetchOne('SELECT * FROM `nx_landing_pages` WHERE `slug` = ? AND `status` = ?', [$slug, self::PUBLISHED]);
    }

    /**
     * Published pages for the sitemap and robots.txt.
     *
     * @return list<array{slug: string, updated_at: string}>
     */
    public function published(): array
    {
        /** @var list<array{slug: string, updated_at: string}> */
        return $this->db->fetchAll('SELECT `slug`, `updated_at` FROM `nx_landing_pages` WHERE `status` = ? ORDER BY `slug` LIMIT 1000', [self::PUBLISHED]);
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_landing_pages` WHERE `slug` = ? AND `id` <> ?', [$slug, $exceptId]) > 0;
    }

    /**
     * @param array{slug: string, status: string, seo_title: ?string, meta_description: ?string, hero_title: string, hero_teaser: ?string, intro: ?string, features: ?string, faq: ?string, website_page: ?string, product_line: ?string} $data
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $now = $this->clock->nowString();
        $row = $data + ['updated_by' => $actorId, 'updated_at' => $now];
        if ($id === null) {
            return $this->db->insert('nx_landing_pages', $row + [
                'published_at' => $data['status'] === self::PUBLISHED ? $now : null,
                'created_by' => $actorId,
                'created_at' => $now,
            ]);
        }
        $current = $this->find($id);
        if ($data['status'] === self::PUBLISHED && ($current['published_at'] ?? null) === null) {
            $row['published_at'] = $now;
        }
        $this->db->update('nx_landing_pages', $row, ['id' => $id]);

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->delete('nx_landing_pages', ['id' => $id]);
    }

    /**
     * Feature sections: one per line, "Title | Text".
     *
     * @return list<array{title: string, text: string}>
     */
    public static function features(?string $text): array
    {
        $features = [];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] !== '') {
                $features[] = ['title' => $parts[0], 'text' => $parts[1] ?? ''];
            }
        }

        return array_slice($features, 0, 24);
    }

    /**
     * FAQ entries: blocks separated by a blank line; the first line is the
     * question, the following lines are the answer.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faq(?string $text): array
    {
        $entries = [];
        foreach (preg_split('/\R\s*\R/', trim((string) $text)) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block) ?: []), static fn (string $line): bool => $line !== ''));
            if (count($lines) >= 2) {
                $entries[] = ['question' => $lines[0], 'answer' => implode("\n", array_slice($lines, 1))];
            }
        }

        return array_slice($entries, 0, 50);
    }
}
