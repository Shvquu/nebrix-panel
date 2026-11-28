<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Help center (FAQ): categories and articles with Markdown bodies.
 *
 * Public lookups only return published articles in published categories.
 * The search runs over title and body with LIKE (works inside transactions
 * and needs no FULLTEXT configuration); title hits rank higher.
 */
class HelpCenter
{
    /** Category slugs that would clash with the help center's own pages. */
    public const RESERVED_SLUGS = ['search', 'suggest', 'assistant', 'articles', 'admin'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    // ------------------------------------------------------------ public

    /**
     * Published categories with their number of published articles.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedCategories(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM `nx_help_articles` a WHERE a.`category_id` = c.`id` AND a.`is_published` = 1) AS article_count
               FROM `nx_help_categories` c WHERE c.`is_published` = 1 ORDER BY c.`sort_order`, c.`title`',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedCategory(string $slug): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_help_categories` WHERE `slug` = ? AND `is_published` = 1', [$slug]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publishedArticles(int $categoryId, int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `category_id`, `slug`, `title`, `body`, `updated_at` FROM `nx_help_articles`
              WHERE `category_id` = ? AND `is_published` = 1 ORDER BY `sort_order`, `title` LIMIT ' . max(1, $limit),
            [$categoryId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedArticle(string $categorySlug, string $slug): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, c.`slug` AS category_slug, c.`title` AS category_title FROM `nx_help_articles` a
               JOIN `nx_help_categories` c ON c.`id` = a.`category_id`
              WHERE c.`slug` = ? AND a.`slug` = ? AND a.`is_published` = 1 AND c.`is_published` = 1',
            [$categorySlug, $slug],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedArticleById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, c.`slug` AS category_slug FROM `nx_help_articles` a JOIN `nx_help_categories` c ON c.`id` = a.`category_id`
              WHERE a.`id` = ? AND a.`is_published` = 1 AND c.`is_published` = 1',
            [$id],
        );
    }

    /**
     * Full-text-like search over published articles.
     *
     * @return list<array<string, mixed>> articles with category_slug, category_title and score
     */
    public function search(string $query, int $limit = 20): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }
        $score = [];
        $where = [];
        $params = [];
        $scoreParams = [];
        foreach ($terms as $term) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $where[] = '(a.`title` LIKE ? OR a.`body` LIKE ?)';
            array_push($params, $like, $like);
            $score[] = '(CASE WHEN a.`title` LIKE ? THEN 3 ELSE 0 END) + (CASE WHEN a.`body` LIKE ? THEN 1 ELSE 0 END)';
            array_push($scoreParams, $like, $like);
        }

        return $this->db->fetchAll(
            'SELECT a.`id`, a.`slug`, a.`title`, a.`body`, a.`updated_at`, c.`slug` AS category_slug, c.`title` AS category_title, (' . implode(' + ', $score) . ') AS score
               FROM `nx_help_articles` a JOIN `nx_help_categories` c ON c.`id` = a.`category_id`
              WHERE a.`is_published` = 1 AND c.`is_published` = 1 AND (' . implode(' OR ', $where) . ')
              ORDER BY score DESC, a.`sort_order`, a.`title` LIMIT ' . max(1, min(50, $limit)),
            [...$scoreParams, ...$params],
        );
    }

    /**
     * Search words: at most 8 terms of 2–40 characters, lower-cased.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}.\-]+/u', mb_strtolower(mb_substr($query, 0, 200))) ?: [];
        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, '.-');
            if (mb_strlen($word) >= 2 && mb_strlen($word) <= 40 && !in_array($word, self::STOP_WORDS, true)) {
                $terms[$word] = true;
            }
        }

        return array_slice(array_keys($terms), 0, 8);
    }

    /** Words too common to narrow a search (English and German). */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'how', 'can', 'what', 'with', 'my', 'is', 'to', 'of', 'in', 'on', 'do', 'does', 'an', 'it', 'not', 'no', 'or', 'be', 'at',
        'der', 'die', 'das', 'und', 'ich', 'wie', 'kann', 'mein', 'meine', 'ist', 'ein', 'eine', 'mit', 'für', 'nicht', 'zu', 'im', 'in', 'auf', 'den', 'es', 'wird',
    ];

    /**
     * Counts a "Was this helpful?" answer.
     */
    public function vote(int $articleId, bool $helpful): void
    {
        $column = $helpful ? '`helpful_yes`' : '`helpful_no`';
        $this->db->run('UPDATE `nx_help_articles` SET ' . $column . ' = ' . $column . ' + 1 WHERE `id` = ? AND `is_published` = 1', [$articleId]);
    }

    // ------------------------------------------------------------- admin

    /**
     * @return list<array<string, mixed>>
     */
    public function allCategories(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM `nx_help_articles` a WHERE a.`category_id` = c.`id`) AS article_count
               FROM `nx_help_categories` c ORDER BY c.`sort_order`, c.`title`',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allArticles(?int $categoryId = null, string $search = ''): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($categoryId !== null) {
            $where[] = 'a.`category_id` = ?';
            $params[] = $categoryId;
        }
        if ($search !== '') {
            $where[] = 'a.`title` LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        return $this->db->fetchAll(
            'SELECT a.`id`, a.`category_id`, a.`slug`, a.`title`, a.`sort_order`, a.`is_published`, a.`helpful_yes`, a.`helpful_no`, a.`updated_at`,
                    c.`title` AS category_title, c.`slug` AS category_slug
               FROM `nx_help_articles` a JOIN `nx_help_categories` c ON c.`id` = a.`category_id`
              WHERE ' . implode(' AND ', $where) . ' ORDER BY c.`sort_order`, c.`title`, a.`sort_order`, a.`title` LIMIT 1000',
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCategory(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_help_categories` WHERE `id` = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findArticle(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, c.`slug` AS category_slug FROM `nx_help_articles` a JOIN `nx_help_categories` c ON c.`id` = a.`category_id` WHERE a.`id` = ?',
            [$id],
        );
    }

    public function categorySlugTaken(string $slug, ?int $exceptId): bool
    {
        return $this->db->fetchValue('SELECT `id` FROM `nx_help_categories` WHERE `slug` = ? AND `id` <> ?', [$slug, $exceptId ?? 0]) !== null;
    }

    public function articleSlugTaken(int $categoryId, string $slug, ?int $exceptId): bool
    {
        return $this->db->fetchValue('SELECT `id` FROM `nx_help_articles` WHERE `category_id` = ? AND `slug` = ? AND `id` <> ?', [$categoryId, $slug, $exceptId ?? 0]) !== null;
    }

    /**
     * @param array{slug: string, title: string, description: ?string, sort_order: int, is_published: bool} $data
     */
    public function saveCategory(?int $id, array $data): int
    {
        $now = $this->clock->nowString();
        $row = [
            'slug' => $data['slug'],
            'title' => mb_substr($data['title'], 0, 150),
            'description' => $data['description'] !== null && $data['description'] !== '' ? mb_substr($data['description'], 0, 255) : null,
            'sort_order' => $data['sort_order'],
            'is_published' => $data['is_published'] ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($id === null) {
            return $this->db->insert('nx_help_categories', $row + ['created_at' => $now]);
        }
        $this->db->update('nx_help_categories', $row, ['id' => $id]);

        return $id;
    }

    /** Categories can only be deleted when they hold no articles (nothing is lost). */
    public function deleteCategory(int $id): bool
    {
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_help_articles` WHERE `category_id` = ?', [$id]) > 0) {
            return false;
        }

        return $this->db->delete('nx_help_categories', ['id' => $id]) > 0;
    }

    /**
     * @param array{category_id: int, slug: string, title: string, body: string, sort_order: int, is_published: bool} $data
     */
    public function saveArticle(?int $id, array $data, int $actorId): int
    {
        $now = $this->clock->nowString();
        $row = [
            'category_id' => $data['category_id'],
            'slug' => $data['slug'],
            'title' => mb_substr($data['title'], 0, 200),
            'body' => $data['body'],
            'sort_order' => $data['sort_order'],
            'is_published' => $data['is_published'] ? 1 : 0,
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];
        if ($id === null) {
            return $this->db->insert('nx_help_articles', $row + ['created_by' => $actorId, 'created_at' => $now, 'published_at' => $data['is_published'] ? $now : null]);
        }
        $previous = $this->findArticle($id);
        if ($data['is_published'] && $previous !== null && $previous['published_at'] === null) {
            $row['published_at'] = $now;
        }
        $this->db->update('nx_help_articles', $row, ['id' => $id]);

        return $id;
    }

    public function deleteArticle(int $id): bool
    {
        return $this->db->delete('nx_help_articles', ['id' => $id]) > 0;
    }

    /** URL-friendly slug from a title (a–z, 0–9, dashes; German umlauts transliterated). */
    public static function slugify(string $title): string
    {
        $title = strtr(mb_strtolower($title), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $slug = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== false ? $ascii : $title)), '-'), 0, 80), '-');

        return $slug !== '' ? $slug : 'article';
    }
}
