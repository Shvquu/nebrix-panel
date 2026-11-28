<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Auth\Role;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\MarketingTestHelpers;

/**
 * Blog: drafts and scheduled posts stay private until their publication
 * date, pagination, Atom feed, latest posts on the start page, cover
 * images, safe Markdown and permissions.
 */
final class BlogTest extends FeatureTestCase
{
    use MarketingTestHelpers;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryMediaStore();
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->settings->set('blog.enabled', true);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryMediaStore();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Neues Rechenzentrum',
            'slug' => 'neues-rechenzentrum',
            'teaser' => 'Wir sind umgezogen.',
            'body' => "## Was ist neu?\nMehr **Leistung**.",
            'author_name' => 'Erika Muster',
            'status' => 'published',
            'published_at' => '',
        ];
    }

    /** A time as the datetime-local value of the form (panel time zone). */
    private function local(string $modify): string
    {
        return $this->clock()->now()->modify($modify)->setTimezone(new DateTimeZone($this->settings->string('general.timezone')))->format('Y-m-d\TH:i');
    }

    private function postId(string $slug): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM nx_blog_posts WHERE slug = ?', [$slug]);
    }

    public function testDraftsAndScheduledPostsAreNotPublicUntilTheirDate(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('UTC')));
        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $this->post('/admin/blog', $this->form());
        $this->post('/admin/blog', $this->form(['slug' => 'black-friday', 'title' => 'Black Friday', 'published_at' => $this->local('+2 days')]));
        $this->post('/admin/blog', $this->form(['slug' => 'entwurf', 'title' => 'Geheimer Entwurf', 'status' => 'draft']));
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_blog_posts'));
        self::assertSame('2026-12-03 10:00:00', $this->db->fetchValue("SELECT published_at FROM nx_blog_posts WHERE slug = 'black-friday'"), 'Stored in UTC.');
        self::assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'blog_post.created'"));
        $index = $this->get('/admin/blog')->body();
        self::assertStringContainsString('scheduled', $index);

        $this->cookies = [];
        $list = $this->get('/blog')->body();
        self::assertStringContainsString('Neues Rechenzentrum', $list);
        self::assertStringNotContainsString('Black Friday', $list);
        self::assertStringNotContainsString('Geheimer Entwurf', $list);
        self::assertSame(200, $this->get('/blog/neues-rechenzentrum')->status());
        self::assertSame(404, $this->get('/blog/black-friday')->status(), 'Scheduled.');
        self::assertSame(404, $this->get('/blog/entwurf')->status(), 'Draft.');
        $feed = $this->get('/blog/feed.xml')->body();
        self::assertStringNotContainsString('black-friday', $feed);
        self::assertStringNotContainsString('entwurf', $feed);
        $sitemap = $this->get('/sitemap.xml')->body();
        self::assertStringContainsString('/blog/neues-rechenzentrum', $sitemap);
        self::assertStringNotContainsString('black-friday', $sitemap);
        self::assertStringNotContainsString('/blog/entwurf', $sitemap);

        // Staff preview scheduled posts and drafts (not indexed).
        $this->actingAs($admin);
        $preview = $this->get('/admin/blog/' . $this->postId('black-friday') . '/preview');
        self::assertSame(200, $preview->status());
        self::assertStringContainsString('scheduled', $preview->body());
        self::assertStringContainsString('noindex, nofollow', $preview->body());

        // The publication date arrives.
        $this->clock()->freeze(new DateTimeImmutable('2026-12-03 10:00:01', new DateTimeZone('UTC')));
        $this->cookies = [];
        self::assertSame(200, $this->get('/blog/black-friday')->status());
        self::assertStringContainsString('Black Friday', $this->get('/blog')->body());
        self::assertSame(404, $this->get('/blog/entwurf')->status());
    }

    public function testPostPageFeedPaginationAndStartPage(): void
    {
        $this->settings->set('blog.per_page', 3);
        $this->settings->set('blog.home_posts', 2);
        $base = $this->clock()->now();
        foreach (range(1, 5) as $n) {
            $this->db->insert('nx_blog_posts', [
                'slug' => 'beitrag-' . $n, 'title' => 'Beitrag ' . $n . ' <b>&</b>', 'teaser' => 'Teaser ' . $n, 'body' => "Text **{$n}** [Link](/products) <script>alert(1)</script>",
                'author_name' => 'Team', 'status' => 'published', 'published_at' => $base->modify('-' . (10 - $n) . ' days')->format('Y-m-d H:i:s'),
                'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
            ]);
        }

        $first = $this->get('/blog')->body();
        self::assertSame(3, substr_count($first, 'class="site-card site-post-card"'));
        self::assertStringContainsString('Beitrag 5 &lt;b&gt;&amp;&lt;/b&gt;', $first, 'Newest first, escaped.');
        self::assertStringContainsString('href="/blog?page=2"', $first);
        $second = $this->get('/blog', ['page' => '2']);
        self::assertSame(2, substr_count($second->body(), 'class="site-card site-post-card"'));
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/blog?page=2">', $second->body());
        self::assertSame(404, $this->get('/blog', ['page' => '3'])->status());

        $post = $this->get('/blog/beitrag-5')->body();
        self::assertStringContainsString('<meta property="og:type" content="article">', $post);
        self::assertStringContainsString('<meta property="article:published_time" content="', $post);
        self::assertStringContainsString('<strong>5</strong>', $post);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $post);
        self::assertStringNotContainsString('<script>alert(1)', $post);
        $graph = $this->jsonLd($post);
        $posting = $this->nodes($graph, 'BlogPosting')[0];
        self::assertSame('Beitrag 5 <b>&</b>', $posting['headline']);
        self::assertSame('Team', $posting['author']['name']);
        self::assertSame('http://localhost/blog/beitrag-5', $posting['url']);
        self::assertSame(['http://localhost/', 'http://localhost/blog', 'http://localhost/blog/beitrag-5'], array_column($this->nodes($graph, 'BreadcrumbList')[0]['itemListElement'], 'item'));

        // Atom feed: valid XML, absolute links, the HTML as escaped text.
        $feed = $this->get('/blog/feed.xml');
        self::assertStringStartsWith('application/atom+xml', (string) $feed->header('Content-Type'));
        $xml = simplexml_load_string($feed->body(), 'SimpleXMLElement', LIBXML_NONET);
        self::assertNotFalse($xml);
        self::assertCount(5, $xml->entry);
        self::assertSame('Beitrag 5 <b>&</b>', (string) $xml->entry[0]->title);
        self::assertStringContainsString('<a href="http://localhost/products">Link</a>', (string) $xml->entry[0]->content);
        self::assertStringNotContainsString('<script>', (string) $xml->entry[0]->content);

        // Start page: latest posts (setting) and the navigation link.
        $home = $this->get('/')->body();
        self::assertStringContainsString('href="/blog/beitrag-5"', $home);
        self::assertStringContainsString('href="/blog/beitrag-4"', $home);
        self::assertStringNotContainsString('href="/blog/beitrag-3"', $home);
        self::assertStringContainsString('class="site-link" href="/blog"', $home);

        $this->settings->set('blog.enabled', false);
        foreach (['/blog', '/blog/beitrag-5', '/blog/feed.xml'] as $path) {
            self::assertSame(404, $this->get($path)->status(), $path);
        }
        $home = $this->get('/')->body();
        self::assertStringNotContainsString('href="/blog', $home);
    }

    public function testCoverImagesArePublicOnlyWithThePost(): void
    {
        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $this->postFile('/admin/blog', 'cover', $this->png(), $this->form(['status' => 'draft']));
        $id = $this->postId('neues-rechenzentrum');
        $cover = (string) $this->db->fetchValue('SELECT cover_image FROM nx_blog_posts WHERE id = ?', [$id]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $cover);
        self::assertSame(200, $this->get('/media/' . $cover)->status(), 'Staff see covers of drafts.');
        self::assertSame('private, no-store', $this->get('/media/' . $cover)->header('Cache-Control'));

        $this->cookies = [];
        self::assertSame(404, $this->get('/media/' . $cover)->status(), 'Not public while the post is a draft.');

        $this->actingAs($admin);
        $rejected = $this->postFile('/admin/blog/' . $id, 'cover', '<html><script>alert(1)</script></html>', $this->form());
        self::assertTrue($rejected->isRedirect());
        self::assertSame('draft', $this->db->fetchValue('SELECT status FROM nx_blog_posts WHERE id = ?', [$id]), 'Nothing saved with an invalid image.');
        $this->post('/admin/blog/' . $id, $this->form());

        $this->cookies = [];
        $image = $this->get('/media/' . $cover);
        self::assertSame(200, $image->status());
        self::assertSame('public, max-age=86400', $image->header('Cache-Control'));
        $post = $this->get('/blog/neues-rechenzentrum')->body();
        self::assertStringContainsString('<meta property="og:image" content="http://localhost/media/' . $cover . '">', $post);
        self::assertSame('http://localhost/media/' . $cover, $this->nodes($this->jsonLd($post), 'BlogPosting')[0]['image']);

        // Removing the cover deletes the file.
        $this->actingAs($admin);
        $this->post('/admin/blog/' . $id, $this->form(['remove_cover' => '1']));
        self::assertNull($this->db->fetchValue('SELECT cover_image FROM nx_blog_posts WHERE id = ?', [$id]));
        self::assertSame(404, $this->get('/media/' . $cover)->status());
    }

    public function testPermissionsAndValidation(): void
    {
        $id = $this->db->insert('nx_blog_posts', ['slug' => 'entwurf', 'title' => 'Entwurf', 'body' => 'Text', 'status' => 'draft', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);

        $this->actingAs($this->createUser());
        self::assertSame(403, $this->get('/admin/blog')->status());
        self::assertSame(403, $this->get('/admin/blog/' . $id . '/preview')->status());

        $this->actingAs($this->staffWith(['content.view']));
        self::assertSame(200, $this->get('/admin/blog')->status());
        self::assertSame(200, $this->get('/admin/blog/' . $id)->status());
        self::assertSame(200, $this->get('/admin/blog/' . $id . '/preview')->status());
        self::assertSame(403, $this->post('/admin/blog', $this->form())->status());
        self::assertSame(403, $this->post('/admin/blog/' . $id, $this->form())->status());
        self::assertSame(403, $this->post('/admin/blog/' . $id . '/delete')->status());

        $this->actingAs($this->staffWith(['content.view', 'content.manage']));
        $this->post('/admin/blog', $this->form(['slug' => 'entwurf']));
        $this->post('/admin/blog', $this->form(['slug' => 'Mit Leerzeichen']));
        $this->post('/admin/blog', $this->form(['published_at' => 'morgen']));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_blog_posts'), 'Duplicate or invalid addresses and dates are refused.');
        $this->assertRedirect($this->post('/admin/blog/' . $id . '/delete'), '/admin/blog');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_blog_posts'));
    }
}
