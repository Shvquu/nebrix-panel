<?php

declare(strict_types=1);

namespace Nebrix\Catalog;

use Nebrix\Billing\Money;
use Nebrix\Security\ValidationException;

/**
 * Server configurator for KVM and LXC products: the customer chooses CPU
 * cores, memory and disk within limits set by staff.
 *
 * The product's own resources (cores, memory_mb, disk_gb) are the included
 * minimum and the product price is the price of that minimum. Each step
 * above it costs the configured step price per billing period (net). The
 * price is always calculated on the server; values from the browser are
 * only the customer's choice.
 */
final class ProductConfigurator
{
    /** Customer option names (memory in whole GB). */
    public const OPTIONS = ['cores', 'memory_gb', 'disk_gb'];

    private const MONEY_RULE = 'nullable|regex:/^\d{1,5}([.,]\d{1,2})?$/';

    /**
     * Product settings added to the KVM and LXC categories.
     *
     * @return array<string, array{label: string, rules: string, default?: mixed, help?: string, type?: string}>
     */
    public static function configFields(): array
    {
        return [
            'configurator' => ['label' => 'Configurator: customers choose cores, memory and disk', 'rules' => 'bool', 'default' => false, 'type' => 'bool', 'help' => 'The resources above are the included minimum; the product price is the price of that minimum. Memory must be whole GB.'],
            'cfg_max_cores' => ['label' => 'Configurator: maximum CPU cores', 'rules' => 'nullable|int|min:1|max:128'],
            'cfg_price_core' => ['label' => 'Configurator: price per additional core (net, per billing period)', 'rules' => self::MONEY_RULE],
            'cfg_max_memory_gb' => ['label' => 'Configurator: maximum memory (GB)', 'rules' => 'nullable|int|min:1|max:1024'],
            'cfg_price_memory_gb' => ['label' => 'Configurator: price per additional GB memory (net)', 'rules' => self::MONEY_RULE],
            'cfg_max_disk_gb' => ['label' => 'Configurator: maximum disk size (GB)', 'rules' => 'nullable|int|min:1|max:65536'],
            'cfg_disk_step_gb' => ['label' => 'Configurator: disk steps (GB)', 'rules' => 'nullable|int|min:1|max:1000', 'default' => 10],
            'cfg_price_disk_step' => ['label' => 'Configurator: price per additional disk step (net)', 'rules' => self::MONEY_RULE],
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function enabled(array $product): bool
    {
        $config = is_array($product['provisioning_config'] ?? null) ? $product['provisioning_config'] : [];

        return in_array($product['category'] ?? null, [ProductCategory::VPS, ProductCategory::LXC], true)
            && ($config['configurator'] ?? false) === true
            && self::configErrors($config, (string) ($product['currency'] ?? 'EUR')) === [];
    }

    /**
     * Ranges of a configurable product (null = not configurable).
     *
     * @param array<string, mixed> $product
     * @return array<string, array{min: int, max: int, step: int, price: int}>|null
     */
    public static function ranges(array $product): ?array
    {
        if (!self::enabled($product)) {
            return null;
        }

        return self::rangesOf($product['provisioning_config'], (string) $product['currency']);
    }

    /**
     * Problems of a product configuration with the configurator switched on
     * (config key => message). Empty when it is off or consistent.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public static function configErrors(array $config, string $currency = 'EUR'): array
    {
        if (($config['configurator'] ?? false) !== true) {
            return [];
        }
        $errors = [];
        if ((int) ($config['memory_mb'] ?? 0) % 1024 !== 0 || (int) ($config['memory_mb'] ?? 0) < 1024) {
            $errors['memory_mb'] = 'With the configurator, memory must be whole GB (a multiple of 1024 MB).';
        }
        $pairs = [
            'cfg_max_cores' => [(int) ($config['cores'] ?? 0), 'cfg_price_core'],
            'cfg_max_memory_gb' => [intdiv((int) ($config['memory_mb'] ?? 0), 1024), 'cfg_price_memory_gb'],
            'cfg_max_disk_gb' => [(int) ($config['disk_gb'] ?? 0), 'cfg_price_disk_step'],
        ];
        $configurable = 0;
        foreach ($pairs as $maxKey => [$min, $priceKey]) {
            $max = $config[$maxKey] ?? null;
            if ($max === null || $max === '') {
                continue;
            }
            if ((int) $max < $min) {
                $errors[$maxKey] = 'The maximum must not be lower than the included value.';
                continue;
            }
            if ((int) $max > $min) {
                $configurable++;
                if (trim((string) ($config[$priceKey] ?? '')) === '') {
                    $errors[$priceKey] = 'Enter a price (0 for free).';
                }
            }
        }
        $step = (int) ($config['cfg_disk_step_gb'] ?? 10);
        $diskMax = $config['cfg_max_disk_gb'] ?? null;
        if ($diskMax !== null && $diskMax !== '' && $step > 0 && ((int) $diskMax - (int) ($config['disk_gb'] ?? 0)) % $step !== 0) {
            $errors['cfg_max_disk_gb'] = 'The maximum disk size must be the included size plus whole steps.';
        }
        if ($configurable === 0) {
            $errors['configurator'] = 'Set a maximum above the included value for at least one resource.';
        }
        foreach (['cfg_price_core', 'cfg_price_memory_gb', 'cfg_price_disk_step'] as $priceKey) {
            $value = trim((string) ($config[$priceKey] ?? ''));
            if ($value === '') {
                continue;
            }
            try {
                if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $value) !== 1) {
                    throw new \InvalidArgumentException('Invalid amount.');
                }
                Money::parse($value, $currency);
            } catch (\InvalidArgumentException) {
                $errors[$priceKey] = 'Enter an amount such as 1.50.';
            }
        }
        return $errors;
    }

    /**
     * The customer's choice with its net price per billing period (before
     * discounts). Missing values mean the included minimum.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $input
     * @param bool $strict false: out-of-range values are clamped (price previews)
     * @return array{cores: int, memory_gb: int, disk_gb: int, price: int}
     *
     * @throws ValidationException
     */
    public static function resolve(array $product, array $input, bool $strict = true): array
    {
        $ranges = self::ranges($product) ?? throw new \LogicException('The product has no configurator.');
        $choice = [];
        $price = (int) $product['price'];
        foreach ($ranges as $name => $range) {
            $raw = $input[$name] ?? null;
            if ($raw === null || $raw === '') {
                $value = $range['min'];
            } elseif ((is_int($raw) || (is_string($raw) && preg_match('/^\d{1,6}$/', $raw) === 1))
                && (int) $raw >= $range['min'] && (int) $raw <= $range['max'] && ((int) $raw - $range['min']) % $range['step'] === 0) {
                $value = (int) $raw;
            } elseif ($strict) {
                throw ValidationException::withMessages([$name => 'Choose one of the offered values.']);
            } else {
                $value = is_numeric($raw) ? max($range['min'], min($range['max'], (int) $raw)) : $range['min'];
                $value -= ($value - $range['min']) % $range['step'];
            }
            $choice[$name] = $value;
            $price += intdiv($value - $range['min'], $range['step']) * $range['price'];
        }

        return ['cores' => $choice['cores'], 'memory_gb' => $choice['memory_gb'], 'disk_gb' => $choice['disk_gb'], 'price' => $price];
    }

    /**
     * Configuration of a new service: product values, replaced by the
     * resources the customer ordered (and paid for), plus the options.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $options order item options
     * @return array<string, mixed>
     */
    public static function serviceConfig(array $product, array $options): array
    {
        $config = is_array($product['provisioning_config'] ?? null) ? $product['provisioning_config'] : [];
        if (in_array($product['category'] ?? null, [ProductCategory::VPS, ProductCategory::LXC], true)) {
            // The order validated these values; the bounds only guard against damaged data.
            if (isset($options['cores']) && is_int($options['cores']) && $options['cores'] >= 1 && $options['cores'] <= 128) {
                $config['cores'] = $options['cores'];
            }
            if (isset($options['memory_gb']) && is_int($options['memory_gb']) && $options['memory_gb'] >= 1 && $options['memory_gb'] <= 1024) {
                $config['memory_mb'] = $options['memory_gb'] * 1024;
            }
            if (isset($options['disk_gb']) && is_int($options['disk_gb']) && $options['disk_gb'] >= 1 && $options['disk_gb'] <= 65536) {
                $config['disk_gb'] = $options['disk_gb'];
            }
        }

        return $config + ['_options' => $options];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array{min: int, max: int, step: int, price: int}>
     */
    private static function rangesOf(array $config, string $currency): array
    {
        $money = static fn (string $key): int => trim((string) ($config[$key] ?? '')) !== '' ? Money::parse((string) $config[$key], $currency) : 0;
        $max = static fn (string $key, int $min): int => ($config[$key] ?? null) !== null && $config[$key] !== '' ? max($min, (int) $config[$key]) : $min;
        $cores = (int) $config['cores'];
        $memory = intdiv((int) $config['memory_mb'], 1024);
        $disk = (int) $config['disk_gb'];

        return [
            'cores' => ['min' => $cores, 'max' => $max('cfg_max_cores', $cores), 'step' => 1, 'price' => $money('cfg_price_core')],
            'memory_gb' => ['min' => $memory, 'max' => $max('cfg_max_memory_gb', $memory), 'step' => 1, 'price' => $money('cfg_price_memory_gb')],
            'disk_gb' => ['min' => $disk, 'max' => $max('cfg_max_disk_gb', $disk), 'step' => max(1, (int) ($config['cfg_disk_step_gb'] ?? 10)), 'price' => $money('cfg_price_disk_step')],
        ];
    }
}
