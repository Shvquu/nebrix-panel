<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Billing\Money;
use Nebrix\Core\Config;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;

/**
 * Search engine and social network data of the public website: canonical
 * address, description, preview image (OpenGraph / Twitter cards) and
 * schema.org structured data (Organization, Product + Offer,
 * BreadcrumbList, BlogPosting, FAQPage).
 *
 * Prices in the structured data are exactly the prices shown on the page
 * (gross, as presented by WebsiteCatalog); sold-out products are marked
 * OutOfStock.
 */
class Seo
{
    /** At most this many products per page in the structured data (keeps the page small). */
    private const MAX_PRODUCTS = 30;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Config $config,
        private readonly Translator $translator,
    ) {
    }

    public function baseUrl(): string
    {
        return rtrim($this->config->string('app.url'), '/');
    }

    public function url(string $path): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }

    /** Public address of an uploaded image (MediaController), or null. */
    public function mediaUrl(?string $name): ?string
    {
        return $name !== null && preg_match(MediaStore::NAME_PATTERN, $name) === 1 ? $this->url('/media/' . $name) : null;
    }

    /** The default preview image (Admin → Settings → Marketing), or null. */
    public function defaultImage(): ?string
    {
        $name = $this->settings->string('seo.og_image');

        return $name !== '' ? $this->mediaUrl($name) : null;
    }

    /**
     * Layout data of one page.
     *
     * @param list<array<string, mixed>> $nodes structured data
     * @param array{type?: string, image?: string|null, description?: string, noindex?: bool, published?: string|null, modified?: string|null} $options
     * @return array{canonical: string|null, type: string, image: string|null, description: string, noindex: bool, published: string|null, modified: string|null, json_ld: \Twig\Markup|null}
     */
    public function page(?string $path, array $nodes = [], array $options = []): array
    {
        $noindex = $options['noindex'] ?? false;

        return [
            // Drafts in the staff preview have no canonical address and are not indexed.
            'canonical' => $path !== null && !$noindex ? $this->url($path) : null,
            'type' => $options['type'] ?? 'website',
            'image' => array_key_exists('image', $options) && $options['image'] !== null ? $options['image'] : $this->defaultImage(),
            'description' => trim((string) ($options['description'] ?? '')),
            'noindex' => $noindex,
            'published' => $options['published'] ?? null,
            'modified' => $options['modified'] ?? null,
            'json_ld' => JsonLd::script($nodes),
        ];
    }

    /**
     * SEO data for the pages of WebsiteController.
     *
     * @param array<string, mixed> $context the template context
     * @return array<string, mixed>
     */
    public function forWebsite(string $template, array $context): array
    {
        $home = [$this->translator->translate('Home'), '/'];
        $products = [$this->translator->translate('Products'), '/products'];

        switch ($template) {
            case 'website/home.twig':
                return $this->page('/', [$this->organization(), $this->webSite()], ['description' => $this->settings->string('seo.home_description')]);
            case 'website/products.twig':
                return $this->page('/products', [$this->breadcrumbs([$home, $products])]);
            case 'website/about.twig':
                return $this->page('/about', [$this->breadcrumbs([$home, [$this->translator->translate('About us'), '/about']])]);
            case 'website/page.twig':
            case 'website/domains.twig':
                $current = is_array($context['current'] ?? null) ? $context['current'] : [];
                $key = $template === 'website/domains.twig' ? 'domains' : (string) ($context['key'] ?? '');
                $path = '/products/' . $key;
                $title = $this->translator->translate((string) ($current['title'] ?? ''));
                $items = [];
                if ($template === 'website/domains.twig') {
                    // The popular endings with the registration price shown on their cards.
                    foreach (is_array($context['cards'] ?? null) ? $context['cards'] : [] as $card) {
                        $items[] = ['price' => (int) $card['register_gross']] + $card;
                    }
                } else {
                    foreach (is_array($context['tabs'] ?? null) ? $context['tabs'] : [] as $tab) {
                        foreach ($tab['products'] as $product) {
                            $items[] = ['price' => (int) $product['price_gross']] + $product;
                        }
                    }
                }

                return $this->page($path, [
                    $this->breadcrumbs([$home, $products, [$title, $path]]),
                    ...$this->products($items, $path, $this->translator->translate((string) ($current['teaser'] ?? ''))),
                ]);
            default:
                return $this->page(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $logo = $this->settings->string('branding.logo_path');
        $sameAs = [];
        foreach (preg_split('/\s+/', $this->settings->string('seo.same_as')) ?: [] as $url) {
            if (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $sameAs[] = $url;
            }
        }

        return [
            '@type' => 'Organization',
            '@id' => $this->url('/') . '#organization',
            'name' => $this->settings->string('general.company_name'),
            'url' => $this->url('/'),
            'logo' => $logo !== '' ? $this->url($logo) : null,
            'email' => $this->settings->string('general.company_email'),
            'telephone' => $this->settings->string('general.company_phone'),
            'address' => trim((string) preg_replace('/\s*\R\s*/', ', ', $this->settings->string('general.company_address'))),
            'vatID' => $this->settings->string('invoice.seller_vat_id'),
            'sameAs' => array_slice(array_values(array_unique($sameAs)), 0, 20),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function webSite(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $this->url('/') . '#website',
            'name' => $this->settings->string('general.company_name'),
            'url' => $this->url('/'),
            'inLanguage' => $this->translator->locale(),
            'publisher' => ['@id' => $this->url('/') . '#organization'],
        ];
    }

    /**
     * @param list<array{0: string, 1: string}> $items name and path, from the start page to the current page
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $items): array
    {
        $list = [];
        foreach (array_values($items) as $position => [$name, $path]) {
            $list[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $name, 'item' => $this->url($path)];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /**
     * Product + Offer per package or configurator shown on a page, with the
     * price as shown (incl. VAT) and the availability.
     *
     * @param list<array<string, mixed>> $products presented products (WebsiteCatalog) with "price" (gross minor units as shown)
     * @return list<array<string, mixed>>
     */
    public function products(array $products, string $path, string $fallbackDescription = ''): array
    {
        $company = $this->settings->string('general.company_name');
        $smallBusiness = $this->settings->bool('tax.small_business');
        $nodes = [];
        foreach (array_slice($products, 0, self::MAX_PRODUCTS) as $product) {
            $currency = (string) $product['currency'];
            $price = Money::toDecimal((int) $product['price'], $currency);
            $months = match ((string) $product['billing_cycle']) {
                'monthly' => 1,
                'quarterly' => 3,
                'semiannually' => 6,
                'annually' => 12,
                default => null,
            };
            $promo = is_array($product['promo'] ?? null) ? $product['promo'] : null;
            $nodes[] = [
                '@type' => 'Product',
                'name' => (string) $product['name'],
                'description' => trim((string) ($product['description'] ?? '')) !== '' ? trim((string) $product['description']) : $fallbackDescription,
                'sku' => (string) $product['slug'],
                'brand' => ['@type' => 'Brand', 'name' => $company],
                'offers' => [
                    '@type' => 'Offer',
                    'url' => $this->url($path),
                    'price' => $price,
                    'priceCurrency' => $currency,
                    'availability' => ($product['sold_out'] ?? false) ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
                    'priceValidUntil' => $promo !== null && ($promo['ends_at'] ?? '') !== '' ? substr((string) $promo['ends_at'], 0, 10) : null,
                    'priceSpecification' => [
                        '@type' => 'UnitPriceSpecification',
                        'price' => $price,
                        'priceCurrency' => $currency,
                        // Small businesses (§ 19 UStG) show final prices without VAT.
                        'valueAddedTaxIncluded' => $smallBusiness ? null : true,
                        'billingDuration' => $months,
                        'unitCode' => $months !== null ? 'MON' : null,
                    ],
                    'seller' => ['@type' => 'Organization', 'name' => $company],
                ],
            ];
        }

        return $nodes;
    }

    /**
     * @param list<array{question: string, answer: string}> $faq
     * @return array<string, mixed>
     */
    public function faq(array $faq): array
    {
        if ($faq === []) {
            return [];
        }

        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $entry): array => [
                '@type' => 'Question',
                'name' => $entry['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $entry['answer']],
            ], $faq),
        ];
    }

    /**
     * @param array<string, mixed> $post row of nx_blog_posts
     * @return array<string, mixed>
     */
    public function blogPosting(array $post, string $path): array
    {
        $author = trim((string) ($post['author_name'] ?? ''));

        return [
            '@type' => 'BlogPosting',
            'headline' => mb_substr((string) $post['title'], 0, 110),
            'description' => (string) ($post['teaser'] ?? ''),
            'url' => $this->url($path),
            'mainEntityOfPage' => $this->url($path),
            'image' => $this->mediaUrl(isset($post['cover_image']) ? (string) $post['cover_image'] : null),
            'datePublished' => self::iso((string) $post['published_at']),
            'dateModified' => self::iso((string) max((string) $post['published_at'], (string) $post['updated_at'])),
            'inLanguage' => $this->translator->locale(),
            'author' => $author !== '' ? ['@type' => 'Person', 'name' => $author] : ['@type' => 'Organization', 'name' => $this->settings->string('general.company_name')],
            'publisher' => $this->publisher(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publisher(): array
    {
        $logo = $this->settings->string('branding.logo_path');

        return [
            '@type' => 'Organization',
            'name' => $this->settings->string('general.company_name'),
            'logo' => $logo !== '' ? ['@type' => 'ImageObject', 'url' => $this->url($logo)] : null,
        ];
    }

    /** UTC database time as ISO 8601 (2026-11-28T10:00:00+00:00). */
    public static function iso(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
