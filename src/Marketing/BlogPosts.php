<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Blog / news posts. A post is public when it is published and its
 * publication date has been reached – a date in the future schedules it.
 */
class BlogPosts
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * All posts for the administration, newest first.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function all(int $page, int $perPage = 25): array
    {
        return [
            'rows' => $this->db->fetchAll('SELECT * FROM `nx_blog_posts` ORDER BY COALESCE(`published_at`, `created_at`) DESC, `id` DESC LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage),
            'total' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_blog_posts`'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_blog_posts` WHERE `id` = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublic(string $slug): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_blog_posts` WHERE `slug` = ? AND `status` = ? AND `published_at` <= ?', [$slug, self::PUBLISHED, $this->clock->nowString()]);
    }

    /**
     * Public posts, newest first.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function publicPage(int $page, int $perPage): array
    {
        $now = $this->clock->nowString();

        return [
            'rows' => $this->db->fetchAll(
                'SELECT * FROM `nx_blog_posts` WHERE `status` = ? AND `published_at` <= ? ORDER BY `published_at` DESC, `id` DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . (max(1, $page) - 1) * $perPage,
                [self::PUBLISHED, $now],
            ),
            'total' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_blog_posts` WHERE `status` = ? AND `published_at` <= ?', [self::PUBLISHED, $now]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit): array
    {
        return $limit > 0 ? $this->publicPage(1, min($limit, 50))['rows'] : [];
    }

    /**
     * Public posts for the sitemap.
     *
     * @return list<array{slug: string, published_at: string, updated_at: string}>
     */
    public function sitemap(): array
    {
        /** @var list<array{slug: string, published_at: string, updated_at: string}> */
        return $this->db->fetchAll(
            'SELECT `slug`, `published_at`, `updated_at` FROM `nx_blog_posts` WHERE `status` = ? AND `published_at` <= ? ORDER BY `published_at` DESC LIMIT 5000',
            [self::PUBLISHED, $this->clock->nowString()],
        );
    }

    /** Whether the image is the cover of a public post. */
    public function isPublicCover(string $name): bool
    {
        return $this->db->fetchValue(
            'SELECT `id` FROM `nx_blog_posts` WHERE `cover_image` = ? AND `status` = ? AND `published_at` <= ? LIMIT 1',
            [$name, self::PUBLISHED, $this->clock->nowString()],
        ) !== null;
    }

    public function isCover(string $name): bool
    {
        return $this->db->fetchValue('SELECT `id` FROM `nx_blog_posts` WHERE `cover_image` = ? LIMIT 1', [$name]) !== null;
    }

    /**
     * @param array<string, mixed> $post
     */
    public function isPublic(array $post): bool
    {
        return $post['status'] === self::PUBLISHED && $post['published_at'] !== null && (string) $post['published_at'] <= $this->clock->nowString();
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_blog_posts` WHERE `slug` = ? AND `id` <> ?', [$slug, $exceptId]) > 0;
    }

    /**
     * @param array{slug: string, title: string, teaser: ?string, body: string, author_name: ?string, status: string, published_at: ?string} $data
     * @param array{cover_image?: ?string} $extra
     */
    public function save(?int $id, array $data, int $actorId, array $extra = []): int
    {
        $now = $this->clock->nowString();
        // Publishing without a date publishes now.
        if ($data['status'] === self::PUBLISHED && $data['published_at'] === null) {
            $data['published_at'] = $now;
        }
        $row = $data + $extra + ['updated_by' => $actorId, 'updated_at' => $now];
        if ($id === null) {
            return $this->db->insert('nx_blog_posts', $row + ['created_by' => $actorId, 'created_at' => $now]);
        }
        $this->db->update('nx_blog_posts', $row, ['id' => $id]);

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->delete('nx_blog_posts', ['id' => $id]);
    }
}
