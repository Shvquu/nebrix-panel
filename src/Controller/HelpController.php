<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\HelpCenter;
use Nebrix\Support\Markdown;
use Nebrix\Website\WebsiteCatalog;
use Twig\Markup;

/**
 * Public help center in the website layout: categories, articles
 * (Markdown rendered with all HTML escaped), search, "Was this helpful?"
 * and the JSON suggestions shown while a customer writes a ticket.
 */
final class HelpController extends Controller
{
    private const VOTES_KEY = '_help_votes';

    public function __construct(
        private readonly HelpCenter $help,
        private readonly SettingsRepository $settings,
        private readonly WebsiteCatalog $catalog,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->ensureEnabled();

        return $this->view($request, 'help/index.twig', ['categories' => $this->help->publishedCategories()]);
    }

    public function search(Request $request): Response
    {
        $this->ensureEnabled();
        $query = mb_substr($request->string('q'), 0, 200);
        $results = [];
        if ($query !== '') {
            if (!$this->limiter->attempt('help-search:' . RateLimiter::network($request->clientIp()), 120, 600)) {
                throw HttpException::tooManyRequests();
            }
            $results = array_map(fn (array $a): array => $a + ['snippet' => $this->snippet((string) $a['body'], $query)], $this->help->search($query, 30));
        }

        return $this->view($request, 'help/search.twig', ['query' => $query, 'results' => $results]);
    }

    public function category(Request $request, string $category): Response
    {
        $this->ensureEnabled();
        $row = $this->help->publishedCategory($category) ?? throw HttpException::notFound();
        $articles = array_map(fn (array $a): array => $a + ['snippet' => $this->snippet((string) $a['body'], '')], $this->help->publishedArticles((int) $row['id']));

        return $this->view($request, 'help/category.twig', ['category' => $row, 'articles' => $articles]);
    }

    public function article(Request $request, string $category, string $article): Response
    {
        $this->ensureEnabled();
        $row = $this->help->publishedArticle($category, $article) ?? throw HttpException::notFound();
        $related = array_values(array_filter($this->help->publishedArticles((int) $row['category_id'], 20), static fn (array $a): bool => (int) $a['id'] !== (int) $row['id']));

        return $this->view($request, 'help/article.twig', [
            'article' => $row,
            // Markdown output: every character of the source is escaped, only generated tags remain.
            'body' => new Markup(Markdown::render((string) $row['body']), 'UTF-8'),
            'related' => array_slice($related, 0, 8),
            'voted' => in_array((int) $row['id'], $this->votes($request), true),
            'feedback' => $request->session()->getFlash('help_feedback'),
        ]);
    }

    /** "Was this helpful?" – one answer per visitor (session) and article, rate-limited per network. */
    public function vote(Request $request, int $id): Response
    {
        $this->ensureEnabled();
        $article = $this->help->publishedArticleById($id) ?? throw HttpException::notFound();
        $helpful = $request->string('helpful');
        if (!in_array($helpful, ['yes', 'no'], true)) {
            throw HttpException::badRequest();
        }
        $target = '/help/' . $article['category_slug'] . '/' . $article['slug'];
        $votes = $this->votes($request);
        if (in_array($id, $votes, true)) {
            $this->flash($request, 'info', 'You have already rated this article.');

            return $this->redirect($target);
        }
        if (!$this->limiter->attempt('help-vote:' . RateLimiter::network($request->clientIp()), 30, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $this->help->vote($id, $helpful === 'yes');
        $request->session()->put(self::VOTES_KEY, array_slice([...$votes, $id], -200));
        $request->session()->flash('help_feedback', $helpful);
        $this->flash($request, 'success', 'Thank you for your feedback.');

        return $this->redirect($target);
    }

    /** Suggestions for the ticket form: matching published articles as JSON. */
    public function suggest(Request $request): Response
    {
        if (!$this->settings->bool('support.help_center_enabled') || !$this->settings->bool('support.ticket_suggestions')) {
            return Response::json(['articles' => []]);
        }
        $query = mb_substr($request->string('q'), 0, 200);
        if (mb_strlen($query) < 3) {
            return Response::json(['articles' => []]);
        }
        if (!$this->limiter->attempt('help-suggest:' . RateLimiter::network($request->clientIp()), 120, 600)) {
            return Response::json(['articles' => []], 429);
        }
        $articles = array_map(static fn (array $a): array => [
            'title' => (string) $a['title'],
            'category' => (string) $a['category_title'],
            'url' => '/help/' . $a['category_slug'] . '/' . $a['slug'],
        ], $this->help->search($query, 5));
        $response = Response::json(['articles' => $articles]);
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return list<int>
     */
    private function votes(Request $request): array
    {
        $votes = $request->session()->get(self::VOTES_KEY, []);

        return is_array($votes) ? array_values(array_map('intval', array_filter($votes, 'is_int'))) : [];
    }

    private function snippet(string $body, string $query): string
    {
        $text = Markdown::toText($body);
        $position = 0;
        foreach (HelpCenter::terms($query) as $term) {
            $found = mb_stripos($text, $term);
            if ($found !== false) {
                $position = max(0, $found - 60);
                break;
            }
        }
        $snippet = mb_substr($text, $position, 220);

        return ($position > 0 ? '… ' : '') . $snippet . (mb_strlen($text) > $position + 220 ? ' …' : '');
    }

    private function ensureEnabled(): void
    {
        if (!$this->settings->bool('support.help_center_enabled')) {
            throw HttpException::notFound();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function view(Request $request, string $template, array $context): Response
    {
        $catalog = $this->catalog->forRequest();

        return $this->render($request, $template, $context + [
            'nav_pages' => $catalog->pages(),
            'nav_menu' => $catalog->menu(),
            'site_sale' => $catalog->bannerSale(),
            'about_page' => trim($this->settings->string('website.about_text')) !== '',
            'small_business' => $catalog->smallBusiness(),
            'trustpilot' => null,
        ]);
    }
}
