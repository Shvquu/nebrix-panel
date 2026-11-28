<?php

declare(strict_types=1);

namespace Nebrix\Website;

use Nebrix\Billing\Money;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Repository\ProductRepository;
use Nebrix\Settings\SettingsRepository;

/**
 * Products for the public website: only active, visible products that are
 * not reserved for a customer group, with consumer prices (VAT included,
 * as required for offers to consumers).
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

    /** @var array<string, list<array<string, mixed>>> prepared products per page (one request) */
    private array $byPage = [];

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * A copy with empty caches for one request (the container shares this
     * service; prices and products must never come from an earlier request).
     */
    public function forRequest(): self
    {
        return new self($this->repository, $this->settings);
    }

    /**
     * Pages that have at least one product, with the lowest price.
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
                if ($cheapest === null || $product['gross'] < $cheapest['gross']) {
                    $cheapest = ['gross' => $product['gross'], 'currency' => $product['currency'], 'cycle' => $product['billing_cycle']];
                }
            }
            $pages[$key] = $page + ['count' => count($products), 'from' => $cheapest];
        }

        return $pages;
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
     * The domain product for a domain name (longest matching TLD), or null.
     *
     * @return array<string, mixed>|null
     */
    public function domainProduct(string $domain): ?array
    {
        $best = null;
        foreach ($this->productsOf('domains') as $product) {
            $tld = (string) $product['tld'];
            if ($tld !== '' && str_ends_with($domain, '.' . $tld) && strlen($domain) > strlen($tld) + 1
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
        $domain = mb_strtolower(trim($input));
        $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = rtrim(explode('/', $domain, 2)[0], '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $domain = is_string($ascii) ? $ascii : '';
        }

        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) === 1 ? $domain : null;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function publicProducts(): array
    {
        return $this->products ??= $this->repository->orderable(null);
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
        // Net step prices: the browser previews the price like Money::tax(); the order page calculates the binding one.
        $ranges = ProductConfigurator::ranges($product);

        return [
            'id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
            'name' => (string) $product['name'],
            'category' => (string) $product['category'],
            'description' => (string) ($product['description'] ?? ''),
            'features' => array_values(array_filter(array_map('trim', explode("\n", (string) ($product['features'] ?? ''))))),
            'currency' => (string) $product['currency'],
            'billing_cycle' => (string) $product['billing_cycle'],
            'recurring' => BillingCycle::isRecurring((string) $product['billing_cycle']),
            'net' => (int) $product['price'],
            'gross' => $gross((int) $product['price']),
            'setup_gross' => $gross((int) $product['setup_fee']),
            'tax_rate_bp' => $rate,
            'specs' => self::specs((string) $product['category'], $config),
            'tld' => $product['category'] === ProductCategory::DOMAIN ? ltrim(mb_strtolower((string) ($config['tld'] ?? '')), '.') : '',
            'configurator' => $ranges,
            'popular' => (int) $product['sort_order'] < 1000,
        ];
    }

    /**
     * Technical data shown on the product cards.
     *
     * @param array<string, mixed> $config
     * @return list<array{label: string, value: string}>
     */
    private static function specs(string $category, array $config): array
    {
        $int = static fn (string $key): int => (int) ($config[$key] ?? 0);
        $gb = static fn (int $mb): string => $mb % 1024 === 0 ? ($mb / 1024) . ' GB' : $mb . ' MB';
        $specs = match ($category) {
            ProductCategory::VPS, ProductCategory::LXC => [
                ['label' => 'CPU cores', 'value' => (string) $int('cores')],
                ['label' => 'Memory', 'value' => $gb($int('memory_mb'))],
                ['label' => 'Disk', 'value' => $int('disk_gb') . ' GB'],
                ['label' => 'Bandwidth', 'value' => $int('rate_mbps') > 0 ? $int('rate_mbps') . ' MB/s' : 'unlimited'],
            ],
            ProductCategory::MINECRAFT => [
                ['label' => 'Memory', 'value' => $gb($int('memory_mb'))],
                ['label' => 'Disk', 'value' => $gb($int('disk_mb'))],
                ['label' => 'CPU', 'value' => $int('cpu_percent') > 0 ? $int('cpu_percent') . ' %' : 'unlimited'],
                ['label' => 'Backups', 'value' => (string) $int('backups')],
                ['label' => 'Databases', 'value' => (string) $int('databases')],
            ],
            ProductCategory::TEAMSPEAK => [['label' => 'Slots', 'value' => (string) $int('slots')]],
            ProductCategory::NEXTCLOUD => [['label' => 'Storage', 'value' => $int('quota_gb') . ' GB']],
            ProductCategory::DEDICATED => trim((string) ($config['hardware'] ?? '')) !== '' ? [['label' => 'Hardware', 'value' => trim((string) $config['hardware'])]] : [],
            default => [],
        };

        return array_values(array_filter($specs, static fn (array $spec): bool => $spec['value'] !== '0' && $spec['value'] !== '0 GB'));
    }
}
