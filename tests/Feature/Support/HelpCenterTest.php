<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use Nebrix\Auth\Role;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Help center: admin CRUD with permission and audit log, public pages with
 * escaped Markdown, search, "Was this helpful?" and ticket suggestions.
 */
final class HelpCenterTest extends FeatureTestCase
{
    private function category(string $title = 'Servers', bool $published = true): int
    {
        $this->post('/admin/help/categories', ['title' => $title, 'description' => 'All about servers', 'sort_order' => '1', 'is_published' => $published ? '1' : '']);

        return (int) $this->db->fetchValue('SELECT `id` FROM `nx_help_categories` WHERE `title` = ?', [$title]);
    }

    private function article(int $category, string $title, string $body, bool $published = true): int
    {
        $this->post('/admin/help/articles', ['category_id' => (string) $category, 'title' => $title, 'body' => $body, 'is_published' => $published ? '1' : '']);

        return (int) $this->db->fetchValue('SELECT `id` FROM `nx_help_articles` WHERE `title` = ?', [$title]);
    }

    public function testStaffManageArticlesAndOnlyPublishedOnesArePublic(): void
    {
        $this->actingAs($this->createUser(Role::SUPPORT));
        $category = $this->category();
        self::assertSame('servers', $this->db->fetchValue('SELECT `slug` FROM `nx_help_categories` WHERE `id` = ?', [$category]));
        $draft = $this->article($category, 'Reset the root password', "Open **Services** and choose *Reset password*.\n\n<script>alert(1)</script>\n\n[Details](javascript:alert(1)) and [docs](https://docs.example.test/x)", false);
        self::assertSame('reset-the-root-password', $this->db->fetchValue('SELECT `slug` FROM `nx_help_articles` WHERE `id` = ?', [$draft]));
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` IN ('help.category_created', 'help.article_created')"));
        self::assertStringContainsString('&lt;script&gt;', $this->get('/admin/help/articles/' . $draft)->body(), 'The preview escapes HTML.');

        // Drafts are not public.
        $this->cookies = [];
        self::assertSame(404, $this->get('/help/servers/reset-the-root-password')->status());
        self::assertStringNotContainsString('Reset the root password', $this->get('/help/search', ['q' => 'root password'])->body());

        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/help/articles/' . $draft, ['category_id' => (string) $category, 'title' => 'Reset the root password', 'slug' => 'reset-the-root-password', 'body' => (string) $this->db->fetchValue('SELECT `body` FROM `nx_help_articles` WHERE `id` = ?', [$draft]), 'is_published' => '1']);
        self::assertNotNull($this->db->fetchValue('SELECT `published_at` FROM `nx_help_articles` WHERE `id` = ?', [$draft]));

        $this->cookies = [];
        $index = $this->get('/help');
        self::assertSame(200, $index->status());
        self::assertStringContainsString('All about servers', $index->body());
        self::assertStringContainsString('/help/servers', $index->body());
        $page = $this->get('/help/servers/reset-the-root-password');
        self::assertSame(200, $page->status());
        $body = $page->body();
        self::assertStringContainsString('<strong>Services</strong>', $body);
        self::assertStringContainsString('<em>Reset password</em>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('href="javascript:', $body);
        self::assertStringContainsString('<a href="https://docs.example.test/x" rel="nofollow noopener">docs</a>', $body);
        self::assertStringContainsString('Was this article helpful?', $body);

        // Search over title and body.
        self::assertStringContainsString('Reset the root password', $this->get('/help/search', ['q' => 'choose reset'])->body());
        self::assertStringContainsString('0 results', $this->get('/help/search', ['q' => 'kubernetes'])->body());
        self::assertSame(200, $this->get('/help/servers')->status());
        self::assertSame(404, $this->get('/help/unknown')->status());

        // A hidden category hides its articles.
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/help/categories/' . $category, ['title' => 'Servers', 'slug' => 'servers', 'is_published' => '']);
        $this->cookies = [];
        self::assertSame(404, $this->get('/help/servers/reset-the-root-password')->status());
        self::assertSame(404, $this->get('/help/servers')->status());
    }

    public function testPermissionsValidationAndDeletion(): void
    {
        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->get('/admin/help')->status());
        $this->post('/admin/help/categories', ['title' => 'Nope', 'is_published' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_help_categories`'));

        $this->actingAs($this->createUser());
        self::assertNotSame(200, $this->get('/admin/help')->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/help/categories', ['title' => 'Search', 'is_published' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_help_categories`'), 'Reserved addresses are refused.');
        $category = $this->category('Billing');
        $this->post('/admin/help/categories', ['title' => 'Billing again', 'slug' => 'billing', 'is_published' => '1']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_help_categories`'), 'Slugs are unique.');
        $article = $this->article($category, 'Pay by bank transfer', 'Use the IBAN on the invoice.');

        // A category with articles cannot be deleted; nothing is lost.
        $this->post('/admin/help/categories/' . $category . '/delete');
        self::assertNotNull($this->db->fetchValue('SELECT `id` FROM `nx_help_categories` WHERE `id` = ?', [$category]));
        $this->post('/admin/help/articles/' . $article . '/delete');
        self::assertNull($this->db->fetchValue('SELECT `id` FROM `nx_help_articles` WHERE `id` = ?', [$article]));
        $this->post('/admin/help/categories/' . $category . '/delete');
        self::assertNull($this->db->fetchValue('SELECT `id` FROM `nx_help_categories` WHERE `id` = ?', [$category]));
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` IN ('help.article_deleted', 'help.category_deleted')"));
    }

    public function testHelpfulVotesOncePerVisitorAndRateLimited(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $article = $this->article($this->category(), 'Connect via SSH', 'Use ssh root@your-ip.');

        $this->cookies = [];
        $this->get('/help/servers/connect-via-ssh');
        $this->post('/help/articles/' . $article . '/vote', ['helpful' => 'yes']);
        $this->post('/help/articles/' . $article . '/vote', ['helpful' => 'no']);
        self::assertSame([1, 0], array_map('intval', array_values((array) $this->db->fetchOne('SELECT `helpful_yes`, `helpful_no` FROM `nx_help_articles` WHERE `id` = ?', [$article]))), 'One answer per visitor.');
        self::assertStringContainsString('Thank you for your feedback.', $this->get('/help/servers/connect-via-ssh')->body());

        // Another visitor; invalid values are refused.
        $this->cookies = [];
        self::assertSame(400, $this->post('/help/articles/' . $article . '/vote', ['helpful' => 'maybe'])->status());
        $this->post('/help/articles/' . $article . '/vote', ['helpful' => 'no']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT `helpful_no` FROM `nx_help_articles` WHERE `id` = ?', [$article]));
        self::assertSame(419, $this->post('/help/articles/' . $article . '/vote', ['helpful' => 'no'], false)->status(), 'CSRF protected.');

        // At most 30 votes per network and hour.
        $limiter = $this->app->container()->get(RateLimiter::class);
        for ($i = 0; $i < 30; $i++) {
            $limiter->hit('help-vote:' . RateLimiter::network($this->clientIp), 3600);
        }
        $this->cookies = [];
        self::assertSame(429, $this->post('/help/articles/' . $article . '/vote', ['helpful' => 'yes'])->status());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT `helpful_yes` FROM `nx_help_articles` WHERE `id` = ?', [$article]));
        self::assertSame(404, $this->post('/help/articles/999999/vote', ['helpful' => 'yes'])->status());
    }
}
