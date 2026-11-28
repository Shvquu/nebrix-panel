<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Auth\User;
use Nebrix\Billing\Money;
use Nebrix\Database\Connection;
use Nebrix\Domain\PremiumDomainException;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\SecurityHeaders;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Website\WebsiteCatalog;
use Psr\Log\LoggerInterface;

/**
 * Public website: start page, product pages with packages and the server
 * configurator, domain search and "About us". Ordering itself happens in
 * the customer area (sign-in required), where prices are calculated again.
 */
final class WebsiteController extends Controller
{
    /** Trustpilot's TrustBox script and frames; only allowed on pages that show the widget. */
    private const TRUSTPILOT_ORIGIN = 'https://widget.trustpilot.com';

    public function __construct(
        private readonly WebsiteCatalog $catalog,
        private readonly SettingsRepository $settings,
        private readonly IntegrationManager $integrations,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
        private readonly \Nebrix\Marketing\SiteContext $marketing,
    ) {
    }

    public function home(Request $request): Response
    {
        if (!$this->settings->bool('website.enabled')) {
            return $this->redirect($request->attribute('user') instanceof User ? '/dashboard' : '/login');
        }
        $catalog = $this->catalog->forRequest();
        $pages = $catalog->pages();

        return $this->withTrustpilot($this->view($request, $catalog, 'website/home.twig', [
            'pages' => $pages,
            'hero_title' => $this->settings->string('website.hero_title'),
            'hero_text' => $this->settings->string('website.hero_text'),
            'show_features' => $this->settings->bool('website.show_features'),
            'show_partner' => $this->settings->bool('website.show_partner'),
            'referral' => $this->settings->bool('referral.enabled') ? ['reward' => Money::parse($this->settings->string('referral.reward')), 'currency' => $this->settings->string('general.default_currency')] : null,
            'api_enabled' => $this->settings->bool('api.enabled'),
            'latest_posts' => $this->marketing->latestPosts(),
        ]));
    }

    public function products(Request $request): Response
    {
        $this->assertEnabled();
        $catalog = $this->catalog->forRequest();

        return $this->view($request, $catalog, 'website/products.twig', ['pages' => $catalog->pages()]);
    }

    public function show(Request $request, string $page): Response
    {
        $this->assertEnabled();
        $catalog = $this->catalog->forRequest();
        $pages = $catalog->pages();
        if (!isset($pages[$page])) {
            throw HttpException::notFound();
        }
        if ($page === 'domains') {
            return $this->domains($request, $catalog, $pages);
        }
        return $this->view($request, $catalog, 'website/page.twig', [
            'key' => $page,
            'current' => $pages[$page],
            'tabs' => $catalog->tabs($page),
            'promotion' => $catalog->promotion($page),
            'benefits' => $this->benefits($page),
        ]);
    }

    public function about(Request $request): Response
    {
        $this->assertEnabled();
        $text = trim($this->settings->string('website.about_text'));
        if ($text === '') {
            throw HttpException::notFound();
        }

        return $this->view($request, $this->catalog->forRequest(), 'website/about.twig', [
            'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $text) ?: []))),
            'company' => [
                'address' => $this->settings->string('general.company_address'),
                'email' => $this->settings->string('general.company_email'),
                'phone' => $this->settings->string('general.company_phone'),
            ],
        ]);
    }

    /**
     * Domain page: search over the popular endings (name only) or for one
     * domain, availability per ending, and the list of all endings.
     *
     * @param array<string, array<string, mixed>> $pages
     */
    private function domains(Request $request, WebsiteCatalog $catalog, array $pages): Response
    {
        $query = mb_substr(trim($request->string('domain')), 0, 260);
        $tlds = $catalog->productsOf('domains');
        $cards = array_slice(array_values(array_filter($tlds, static fn (array $p): bool => $p['popular'] && !$p['sold_out'])), 0, 6);
        $statuses = [];
        $result = null;
        if ($query !== '') {
            $domain = WebsiteCatalog::normaliseDomain($query);
            $product = $domain !== null ? $catalog->domainProduct($domain) : null;
            // The name in front of the ending ("projekt" of projekt.co.uk); sub-domains are not checked on the cards.
            $label = match (true) {
                $product !== null => substr((string) $domain, 0, -strlen((string) $product['tld']) - 1),
                $domain !== null => explode('.', $domain, 2)[0],
                default => WebsiteCatalog::normaliseLabel($query),
            };
            if ($label === null) {
                $result = ['query' => $query, 'domain' => null, 'product' => null, 'status' => 'invalid'];
            } else {
                // The popular endings for the name …
                if (!str_contains($label, '.')) {
                    foreach ($cards as $card) {
                        $statuses[$card['tld']] = ['domain' => $label . '.' . $card['tld'], 'status' => $this->availability($request, $label . '.' . $card['tld'], $card['id'])];
                    }
                }
                // … and the exact domain when its ending is not on a card.
                if ($domain !== null && ($product === null || ($statuses[$product['tld']]['domain'] ?? null) !== $domain)) {
                    $result = ['query' => $query, 'domain' => $domain, 'product' => $product, 'status' => $product === null ? 'unsupported' : $this->availability($request, $domain, (int) $product['id'])];
                }
            }
        }

        return $this->view($request, $catalog, 'website/domains.twig', [
            'current' => $pages['domains'],
            'query' => $query,
            'result' => $result,
            'cards' => $cards,
            'statuses' => $statuses,
            'tlds' => $tlds,
            'benefits' => $this->benefits('domains'),
        ]);
    }

    /**
     * available | taken | premium | limited | unknown (registrar not reachable)
     */
    private function availability(Request $request, string $domain, int $productId): string
    {
        // Every check is a request to the registrar: limited per visitor network.
        if (!$this->limiter->attempt('website-domain-check:' . RateLimiter::network($request->clientIp()), 60, 600)) {
            return 'limited';
        }
        $integrationId = (int) ($this->db->fetchValue('SELECT `integration_id` FROM `nx_products` WHERE `id` = ?', [$productId]) ?? 0);
        if ($integrationId <= 0) {
            return 'unknown';
        }
        try {
            return $this->integrations->registrar($this->integrations->get($integrationId))->isAvailable($domain) ? 'available' : 'taken';
        } catch (PremiumDomainException) {
            return 'premium';
        } catch (IntegrationException $exception) {
            $this->logger->warning('Website domain check failed: ' . $exception->getMessage(), ['integration' => $integrationId]);

            return 'unknown';
        }
    }

    /**
     * Advantages of a page from the settings ("Title | Text" per line).
     *
     * @return list<array{title: string, text: string}>
     */
    private function benefits(string $page): array
    {
        $benefits = [];
        foreach (preg_split('/\R/', $this->settings->string('website.benefits_' . $page)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] !== '') {
                $benefits[] = ['title' => $parts[0], 'text' => $parts[1] ?? ''];
            }
        }

        return array_slice($benefits, 0, 12);
    }

    private function assertEnabled(): void
    {
        if (!$this->settings->bool('website.enabled')) {
            throw HttpException::notFound();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function view(Request $request, WebsiteCatalog $catalog, string $template, array $context): Response
    {
        return $this->render($request, $template, $context + [
            'nav_pages' => $catalog->pages(),
            'nav_menu' => $catalog->menu(),
            'site_sale' => $catalog->bannerSale(),
            'about_page' => trim($this->settings->string('website.about_text')) !== '',
            'small_business' => $catalog->smallBusiness(),
            'trustpilot' => $this->trustpilot(),
        ] + $this->marketing->website($template, $context));
    }

    /**
     * @return array{domain: string, business_unit: string, template: string}|null
     */
    private function trustpilot(): ?array
    {
        $domain = $this->settings->string('website.trustpilot_domain');
        if ($domain === '') {
            return null;
        }

        return [
            'domain' => $domain,
            'business_unit' => $this->settings->string('website.trustpilot_business_unit'),
            'template' => $this->settings->string('website.trustpilot_template'),
        ];
    }

    /** The TrustBox is loaded on request only; its origin must still be allowed by the policy. */
    private function withTrustpilot(Response $response): Response
    {
        $trustpilot = $this->trustpilot();
        if ($trustpilot !== null && $trustpilot['business_unit'] !== '' && $trustpilot['template'] !== '') {
            $response->setHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy([
                'script-src' => [self::TRUSTPILOT_ORIGIN],
                'frame-src' => [self::TRUSTPILOT_ORIGIN],
                'connect-src' => [self::TRUSTPILOT_ORIGIN],
                'img-src' => [self::TRUSTPILOT_ORIGIN],
                'style-src' => [self::TRUSTPILOT_ORIGIN],
            ]));
        }

        return $response;
    }
}
