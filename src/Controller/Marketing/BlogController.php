<?php

declare(strict_types=1);

namespace Nebrix\Controller\Marketing;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\BlogPosts;
use Nebrix\Marketing\Markdown;
use Nebrix\Marketing\Seo;
use Nebrix\Marketing\SiteContext;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;
use Twig\Markup;

/**
 * Public blog: /blog (paginated), /blog/{slug} and the Atom feed
 * /blog/feed.xml. Drafts and scheduled posts (publication date in the
 * future) are not public.
 */
final class BlogController extends Controller
{
    private const FEED_ENTRIES = 20;

    public function __construct(
        private readonly BlogPosts $posts,
        private readonly SiteContext $site,
        private readonly Seo $seo,
        private readonly SettingsRepository $settings,
        private readonly Translator $translator,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->assertEnabled();
        $page = $this->page($request);
        $perPage = max(1, $this->settings->int('blog.per_page'));
        $result = $this->posts->publicPage($page, $perPage);
        if ($page > 1 && $result['rows'] === []) {
            throw HttpException::notFound();
        }
        $path = '/blog' . ($page > 1 ? '?page=' . $page : '');

        return $this->render($request, 'website/blog/index.twig', [
            'posts' => $result['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'seo' => $this->seo->page($path, [
                $this->seo->breadcrumbs([[$this->translator->translate('Home'), '/'], [$this->translator->translate('Blog'), '/blog']]),
            ], ['description' => $this->translator->translate('News, tips and updates from :brand.', ['brand' => $this->settings->string('general.company_name')])]),
        ] + $this->site->layout());
    }

    public function show(Request $request, string $slug): Response
    {
        $this->assertEnabled();
        $post = $this->posts->findPublic($slug) ?? throw HttpException::notFound();

        return $this->present($request, $post, false);
    }

    /**
     * Renders a post; also used for the staff preview of drafts and
     * scheduled posts (no canonical address, not indexed).
     *
     * @param array<string, mixed> $post
     */
    public function present(Request $request, array $post, bool $preview): Response
    {
        $path = '/blog/' . $post['slug'];
        $cover = $this->seo->mediaUrl(isset($post['cover_image']) ? (string) $post['cover_image'] : null);
        $published = $post['published_at'] !== null ? Seo::iso((string) $post['published_at']) : null;

        return $this->render($request, 'website/blog/show.twig', [
            'post' => $post,
            'body' => new Markup(Markdown::toHtml((string) $post['body'], $this->seo->baseUrl()), 'UTF-8'),
            'preview' => $preview,
            'scheduled' => $post['status'] === BlogPosts::PUBLISHED && !$this->posts->isPublic($post),
            'seo' => $this->seo->page($path, [
                $this->seo->breadcrumbs([[$this->translator->translate('Home'), '/'], [$this->translator->translate('Blog'), '/blog'], [(string) $post['title'], $path]]),
                $post['published_at'] !== null ? $this->seo->blogPosting($post, $path) : [],
            ], [
                'type' => 'article',
                'image' => $cover,
                'description' => trim((string) ($post['teaser'] ?? '')) !== '' ? (string) $post['teaser'] : Markdown::toPlainText((string) $post['body'], 160),
                'noindex' => $preview,
                'published' => $published,
                'modified' => $published !== null ? Seo::iso(max((string) $post['published_at'], (string) $post['updated_at'])) : null,
            ]),
        ] + $this->site->layout());
    }

    /** Atom feed of the latest public posts. */
    public function feed(Request $request): Response
    {
        $this->assertEnabled();
        $posts = $this->posts->latest(self::FEED_ENTRIES);
        $company = $this->settings->string('general.company_name');
        $base = $this->seo->baseUrl();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xml:lang', $this->translator->locale());
        $xml->writeElement('id', $this->seo->url('/blog'));
        $xml->writeElement('title', $company . ' – ' . $this->translator->translate('Blog'));
        $xml->writeElement('updated', $posts !== [] ? Seo::iso(max(array_map(static fn (array $p): string => max((string) $p['published_at'], (string) $p['updated_at']), $posts))) : Seo::iso('2026-01-01 00:00:00'));
        $this->link($xml, $this->seo->url('/blog/feed.xml'), 'self', 'application/atom+xml');
        $this->link($xml, $this->seo->url('/blog'), 'alternate', 'text/html');
        $xml->startElement('author');
        $xml->writeElement('name', $company);
        $xml->endElement();
        foreach ($posts as $post) {
            $url = $this->seo->url('/blog/' . $post['slug']);
            $xml->startElement('entry');
            $xml->writeElement('id', $url);
            $xml->writeElement('title', (string) $post['title']);
            $xml->writeElement('published', Seo::iso((string) $post['published_at']));
            $xml->writeElement('updated', Seo::iso(max((string) $post['published_at'], (string) $post['updated_at'])));
            $this->link($xml, $url, 'alternate', 'text/html');
            if (trim((string) ($post['author_name'] ?? '')) !== '') {
                $xml->startElement('author');
                $xml->writeElement('name', (string) $post['author_name']);
                $xml->endElement();
            }
            if (trim((string) ($post['teaser'] ?? '')) !== '') {
                $xml->writeElement('summary', (string) $post['teaser']);
            }
            // The rendered (safe) HTML, escaped as XML text; links absolute for feed readers.
            $xml->startElement('content');
            $xml->writeAttribute('type', 'html');
            $xml->text(Markdown::toHtml((string) $post['body'], $base, true));
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return new Response($xml->outputMemory(), 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    private function link(\XMLWriter $xml, string $href, string $rel, string $type): void
    {
        $xml->startElement('link');
        $xml->writeAttribute('rel', $rel);
        $xml->writeAttribute('type', $type);
        $xml->writeAttribute('href', $href);
        $xml->endElement();
    }

    private function assertEnabled(): void
    {
        if (!$this->site->blogEnabled()) {
            throw HttpException::notFound();
        }
    }
}
