<?php

declare(strict_types=1);

namespace Nebrix\Website;

use Nebrix\Billing\Money;
use Nebrix\Billing\Sales\SaleService;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Catalog\ProductPricing;
use Nebrix\Core\Clock;
use Nebrix\Repository\ProductRepository;
use Nebrix\Settings\SettingsRepository;

/**
 * Products for the public website: active, visible products that are not
 * reserved for a customer group (sold-out ones are shown as sold out), with
 * consumer prices (VAT included, as required for offers to consumers) and
 * running promotions with the previous lowest price (§ 11 PAngV).
 */
final class WebsiteCatalog
{
    /**
     * Product pages of the website: address => categories, title, icon, teaser.
     *
     * @var array<string, array{categories: list<string>, title: string, icon: string, teaser: string}>
     */
    public const PAGES = [
        'vserver' => ['categories' => [ProductCategory::LXC], 'title' => 'vServer (LXC)', 'icon' => 'server', 'teaser' => 'Lightweight Linux containers with full root access – ideal for websites, bots and services.'],
        'kvm' => ['categories' => [ProductCategory::VPS], 'title' => 'KVM server', 'icon' => 'cpu', 'teaser' => 'Fully virtualised servers with their own kernel and console access.'],
        'dedicated' => ['categories' => [ProductCategory::DEDICATED], 'title' => 'Dedicated server', 'icon' => 'box', 'teaser' => 'Hardware for you alone – full performance without sharing.'],
        'gameserver' => ['categories' => [ProductCategory::MINECRAFT], 'title' => 'Game server', 'icon' => 'gamepad', 'teaser' => 'Game servers with web interface, backups and databases.'],
        'teamspeak' => ['categories' => [ProductCategory::TEAMSPEAK], 'title' => 'TeamSpeak', 'icon' => 'headset', 'teaser' => 'Your own TeamSpeak 3 server, ready in seconds.'],
        'webhosting' => ['categories' => [ProductCategory::WEBHOSTING], 'title' => 'Webhosting', 'icon' => 'layers', 'teaser' => 'Websites, e-mail and databases with Plesk.'],
        'domains' => ['categories' => [ProductCategory::DOMAIN], 'title' => 'Domains', 'icon' => 'globe', 'teaser' => 'Register your domain or bring it with you.'],
        'cloud' => ['categories' => [ProductCategory::NEXTCLOUD], 'title' => 'Cloud storage', 'icon' => 'cloud', 'teaser' => 'Nextcloud storage for files, calendars and contacts.'],
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $products = null;

    /** @var list<array<string, mixed>>|null running sales (one request) */
    private ?array $runningSales = null;

    /** @var array<string, list<array<string, mixed>>> prepared products per page (one request) */
    private array $byPage = [];

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly SettingsRepository $settings,
        private readonly Clock $clock,
        private readonly SaleService $sales,
    ) {
    }

    /**
     * A copy with empty caches for one request (the container shares this
     * service; prices and products must never come from an earlier request).
     */
    public function forRequest(): self
    {
        return new self($this->repository, $this->settings, $this->clock, $this->sales);
    }

    /**
     * Pages that have at least one product, with the lowest available price.
     *
     * @return array<string, array{categories: list<string>, title: string, icon: string, teaser: string, count: int, from: array{gross: int, currency: string, cycle: string}|null}>
     */
    public function pages(): array
    {
        $pages = [];
        foreach (self::PAGES as $key => $page) {
            $products = $this->productsOf($key);
            if ($products === []) {
                continue;
            }
            $cheapest = null;
            foreach ($products as $product) {
                if (!$product['sold_out'] && ($cheapest === null || $product['price_gross'] < $cheapest['gross'])) {
                    $cheapest = ['gross' => $product['price_gross'], 'currency' => $product['currency'], 'cycle' => $product['billing_cycle']];
                }
            }
            $pages[$key] = $page + ['count' => count($products), 'from' => $cheapest];
        }

        return $pages;
    }

    /**
     * Pages grouped like the product groups of the customer area (menu).
     *
     * @return array<string, array{label: string, icon: string, pages: array<string, array<string, mixed>>}>
     */
    public function menu(): array
    {
        $menu = [];
        foreach (ProductCategory::groups() as $key => $group) {
            $menu[$key] = ['label' => $group['label'], 'icon' => $group['icon'], 'pages' => []];
        }
        foreach ($this->pages() as $key => $page) {
            $menu[ProductCategory::groupOf($page['categories'][0])]['pages'][$key] = $page;
        }

        return array_filter($menu, static fn (array $group): bool => $group['pages'] !== []);
    }

    /**
     * Products of one page, prepared for display.
     *
     * @return list<array<string, mixed>>
     */
    public function productsOf(string $page): array
    {
        if (isset($this->byPage[$page])) {
            return $this->byPage[$page];
        }
        $categories = self::PAGES[$page]['categories'] ?? [];
        $items = [];
        foreach ($this->publicProducts() as $product) {
            if (in_array($product['category'], $categories, true)) {
                $items[] = $this->present($product);
            }
        }

        return $this->byPage[$page] = $items;
    }

    /**
     * Tabs of a product page: one per product line and kind (configurator,
     * packages), in the order of the products.
     *
     * @return list<array{key: string, line: string|null, kind: string, products: list<array<string, mixed>>, sold_out: bool}>
     */
    public function tabs(string $page): array
    {
        $tabs = [];
        foreach ($this->productsOf($page) as $product) {
            $kind = $product['configurator'] !== null ? 'configurator' : 'packages';
            $key = $kind . ':' . ($product['line'] ?? '');
            $tabs[$key] ??= ['key' => 'tab-' . substr(hash('sha256', $key), 0, 10), 'line' => $product['line'], 'kind' => $kind, 'products' => [], 'sold_out' => true];
            $tabs[$key]['products'][] = $product;
            $tabs[$key]['sold_out'] = $tabs[$key]['sold_out'] && $product['sold_out'];
        }

        return array_values($tabs);
    }

    /**
     * Running promotions of a page: highest discount, earliest end, whether
     * stock is limited.
     *
     * @return array{bp: int, percent: string, ends_at: string, limited: bool, permanent: bool, name: string|null}|null
     */
    public function promotion(string $page): ?array
    {
        $promotion = null;
        foreach ($this->productsOf($page) as $product) {
            if ($product['promo'] === null || $product['sold_out']) {
                continue;
            }
            $bp = max($promotion['bp'] ?? 0, $product['promo']['bp']);
            $promotion = [
                'bp' => $bp,
                'percent' => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.'),
                'ends_at' => min($promotion['ends_at'] ?? $product['promo']['ends_at'], $product['promo']['ends_at']),
                'limited' => ($promotion['limited'] ?? false) || $product['stock'] !== null,
                'permanent' => ($promotion['permanent'] ?? true) && $product['promo']['permanent'],
                'name' => $promotion['name'] ?? $product['promo']['name'],
            ];
        }

        return $promotion;
    }

    /**
     * The domain product for a domain name (longest matching TLD), or null.
     *
     * @return array<string, mixed>|null
     */
    public function domainProduct(string $domain): ?array
    {
        $best = null;
        foreach ($this->productsOf('domains') as $product) {
            $tld = (string) $product['tld'];
            if ($tld !== '' && !$product['sold_out'] && str_ends_with($domain, '.' . $tld) && strlen($domain) > strlen($tld) + 1
                && ($best === null || strlen($tld) > strlen((string) $best['tld']))) {
                $best = $product;
            }
        }

        return $best;
    }

    /**
     * Normalises what visitors type ("https://www.Example.de/") to a domain
     * name, or null when it is none.
     */
    public static function normaliseDomain(string $input): ?string
    {
        $domain = self::lowerAscii($input);
        // "www.example.de" means the domain example.de.
        if ($domain !== null && str_starts_with($domain, 'www.') && substr_count($domain, '.') >= 2) {
            $domain = substr($domain, 4);
        }

        return $domain !== null && preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) === 1 ? $domain : null;
    }

    /**
     * A name without ending ("mein-projekt") for the search over the popular
     * endings, or null.
     */
    public static function normaliseLabel(string $input): ?string
    {
        $label = self::lowerAscii($input);

        return $label !== null && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1 ? $label : null;
    }

    /**
     * The running sale for the site-wide banner (highest discount), or null.
     *
     * @return array<string, mixed>|null
     */
    public function bannerSale(): ?array
    {
        foreach ($this->runningSales ??= $this->sales->running() as $sale) {
            if ($sale['show_banner']) {
                return $sale;
            }
        }

        return null;
    }

    /** VAT included in website prices (0 for small businesses under § 19 UStG). */
    public function taxRate(int $productRateBp): int
    {
        return $this->settings->bool('tax.small_business') ? 0 : $productRateBp;
    }

    public function smallBusiness(): bool
    {
        return $this->settings->bool('tax.small_business');
    }

    private static function lowerAscii(string $input): ?string
    {
        $value = mb_strtolower(trim($input));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = rtrim(explode('/', $value, 2)[0], '.');
        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $value = is_string($ascii) ? $ascii : '';
        }

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicProducts(): array
    {
        return $this->products ??= $this->repository->publicListing();
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function present(array $product): array
    {
        $rate = $this->taxRate((int) $product['tax_rate_bp']);
        $gross = static fn (int $net): int => $net + Money::tax($net, $rate);
        $config = is_array($product['provisioning_config']) ? $product['provisioning_config'] : [];
        $list = (int) $product['price'];
        // Product promotion or global sale – the higher discount (they never add up).
        $discounts = ProductPricing::discounts($product, SaleService::bestFor($this->runningSales ??= $this->sales->running(), $product), $this->clock->now());
        $promoBp = $discounts['first_bp'];
        $promo = null;
        if ($promoBp > 0) {
            // Previous price = lowest list price of the 30 days before the promotion started (§ 11 PAngV).
            $reference = $this->repository->lowestPriceBefore((int) $product['id'], $discounts['starts_at'] ?? $this->clock->nowString()) ?? $list;
            $promoted = $list - Money::discount($list, $promoBp);
            $promo = [
                'bp' => $promoBp,
                'percent' => rtrim(rtrim(number_format($promoBp / 100, 2, '.', ''), '0'), '.'),
                'permanent' => $discounts['first_bp'] === $discounts['permanent_bp'],
                'permanent_bp' => $discounts['permanent_bp'],
                // After the first billing period (first-period sales only).
                'then_gross' => $gross($list - Money::discount($list, $discounts['permanent_bp'])),
                'name' => $discounts['sale'] !== null ? (string) $discounts['sale']['name'] : null,
                'ends_at' => (string) $discounts['ends_at'],
                'reference_net' => $reference,
                'reference_gross' => $reference > $promoted ? $gross($reference) : null,
                'saving_gross' => $reference > $promoted ? $gross($reference) - $gross($promoted) : 0,
            ];
        }
        $net = $list - Money::discount($list, $promoBp);
        // Net step prices: the browser previews the price like Money::tax(); the order page calculates the binding one.
        $ranges = ProductConfigurator::ranges($product);
        foreach ($ranges ?? [] as $name => $range) {
            // Scale below the slider: every value for short ranges, otherwise five evenly spread values.
            $count = intdiv($range['max'] - $range['min'], $range['step']);
            $ranges[$name]['ticks'] = $count <= 16
                ? range($range['min'], $range['max'], $range['step'])
                : array_values(array_unique(array_map(static fn (int $n): int => $range['min'] + (int) round($count * $n / 4) * $range['step'], range(0, 4))));
        }
        $register = ProductPricing::domainFirstYear($product, false);
        $transfer = ProductPricing::domainFirstYear($product, true);

        return [
            'id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
            'category' => (string) $product['category'],
            'line' => trim((string) ($product['website_line'] ?? '')) !== '' ? trim((string) $product['website_line']) : null,
            'description' => (string) ($product['description'] ?? ''),
            'sections' => self::sections((string) ($product['features'] ?? '')),
            'currency' => (string) $product['currency'],
            'billing_cycle' => (string) $product['billing_cycle'],
            'recurring' => BillingCycle::isRecurring((string) $product['billing_cycle']),
            'net' => $list,
            'price_gross' => $gross($net),
            'setup_gross' => $gross((int) $product['setup_fee']),
            'tax_rate_bp' => $rate,
            'promo' => $promo,
            'stock' => $product['stock'] !== null ? (int) $product['stock'] : null,
            'sold_out' => $product['stock'] !== null && (int) $product['stock'] <= 0,
            'specs' => self::specs((string) $product['category'], $config),
            'tld' => $product['category'] === ProductCategory::DOMAIN ? ltrim(mb_strtolower((string) ($config['tld'] ?? '')), '.') : '',
            'register_gross' => $register !== null ? $gross($register) : $gross($net),
            'transfer_gross' => $transfer !== null ? $gross($transfer) : $gross($net),
            'configurator' => $ranges,
            'popular' => (int) $product['sort_order'] < 1000,
        ];
    }

    /**
     * Feature list with optional headings: a line "# Network" starts a
     * section; lines before the first heading form a section without title.
     *
     * @return list<array{title: string|null, items: list<string>}>
     */
    public static function sections(string $features): array
    {
        $sections = [];
        $current = ['title' => null, 'items' => []];
        foreach (preg_split('/\R/', $features) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                if ($current['title'] !== null || $current['items'] !== []) {
                    $sections[] = $current;
                }
                $current = ['title' => trim(ltrim($line, '#')), 'items' => []];
                continue;
            }
            $current['items'][] = $line;
        }
        if ($current['title'] !== null || $current['items'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * Technical data of a product ("Configuration" on the cards): text to
     * translate with its values.
     *
     * @param array<string, mixed> $config
     * @return list<array{text: string, params: array<string, string>}>
     */
    private static function specs(string $category, array $config): array
    {
        $int = static fn (string $key): int => (int) ($config[$key] ?? 0);
        $gb = static fn (int $mb): string => $mb % 1024 === 0 ? (string) ($mb / 1024) : number_format($mb / 1024, 1, '.', '');
        $spec = static fn (string $text, array $params = []): array => ['text' => $text, 'params' => array_map('strval', $params)];
        $specs = match ($category) {
            ProductCategory::VPS, ProductCategory::LXC => [
                $spec(':n cores', ['n' => $int('cores')]),
                $spec(':n GB memory', ['n' => $gb($int('memory_mb'))]),
                $spec(':n GB storage', ['n' => $int('disk_gb')]),
                $int('rate_mbps') > 0 ? $spec(':n MB/s bandwidth', ['n' => $int('rate_mbps')]) : null,
            ],
            ProductCategory::MINECRAFT => [
                $spec(':n GB memory', ['n' => $gb($int('memory_mb'))]),
                $spec(':n GB storage', ['n' => $gb($int('disk_mb'))]),
                $int('cpu_percent') > 0 ? ($int('cpu_percent') % 100 === 0 ? $spec(':n cores', ['n' => intdiv($int('cpu_percent'), 100)]) : $spec(':n % CPU', ['n' => $int('cpu_percent')])) : null,
                $int('backups') > 0 ? $spec(':n backups', ['n' => $int('backups')]) : null,
                $int('databases') > 0 ? $spec(':n databases', ['n' => $int('databases')]) : null,
                $int('allocations') > 0 ? $spec(':n additional ports', ['n' => $int('allocations')]) : null,
            ],
            ProductCategory::TEAMSPEAK => [$spec(':n slots', ['n' => $int('slots')])],
            ProductCategory::NEXTCLOUD => [$spec(':n GB storage', ['n' => $int('quota_gb')])],
            ProductCategory::DEDICATED => trim((string) ($config['hardware'] ?? '')) !== '' ? [$spec(':text', ['text' => trim((string) $config['hardware'])])] : [],
            default => [],
        };

        return array_values(array_filter($specs));
    }
}
