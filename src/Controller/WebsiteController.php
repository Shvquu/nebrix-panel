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
        $products = $catalog->productsOf($page);

        return $this->view($request, $catalog, 'website/page.twig', [
            'key' => $page,
            'current' => $pages[$page],
            'products' => array_values(array_filter($products, static fn (array $p): bool => $p['configurator'] === null)),
            'configurable' => array_values(array_filter($products, static fn (array $p): bool => $p['configurator'] !== null)),
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
     * Domain page: search with availability check and the list of endings.
     *
     * @param array<string, array<string, mixed>> $pages
     */
    private function domains(Request $request, WebsiteCatalog $catalog, array $pages): Response
    {
        $query = mb_substr(trim($request->string('domain')), 0, 260);
        $result = null;
        if ($query !== '') {
            $domain = WebsiteCatalog::normaliseDomain($query);
            $product = $domain !== null ? $catalog->domainProduct($domain) : null;
            $result = ['query' => $query, 'domain' => $domain, 'product' => $product, 'status' => $domain === null ? 'invalid' : ($product === null ? 'unsupported' : 'unknown')];
            if ($domain !== null && $product !== null) {
                $result['status'] = $this->availability($request, $domain, (int) $product['id']);
            }
        }
        $tlds = $catalog->productsOf('domains');

        return $this->view($request, $catalog, 'website/domains.twig', [
            'current' => $pages['domains'],
            'result' => $result,
            'popular' => array_slice(array_values(array_filter($tlds, static fn (array $p): bool => $p['popular'])), 0, 12),
            'tlds' => $tlds,
        ]);
    }

    /**
     * available | taken | premium | unknown (registrar not reachable or limit reached)
     */
    private function availability(Request $request, string $domain, int $productId): string
    {
        // Every check is a request to the registrar: limited per visitor network.
        if (!$this->limiter->attempt('website-domain-check:' . RateLimiter::network($request->clientIp()), 20, 600)) {
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
            'about_page' => trim($this->settings->string('website.about_text')) !== '',
            'small_business' => $catalog->smallBusiness(),
            'trustpilot' => $this->trustpilot(),
        ]);
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
