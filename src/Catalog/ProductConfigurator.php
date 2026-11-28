<?php

declare(strict_types=1);

namespace Nebrix\Catalog;

use Nebrix\Billing\Money;
use Nebrix\Security\ValidationException;

/**
 * Configurator: the customer chooses resources within limits set by staff –
 * CPU cores, memory and disk for KVM/LXC servers, slots for TeamSpeak.
 *
 * The product's own values (e.g. cores, memory_mb, disk_gb, slots) are the
 * included minimum and the product price is the price of that minimum. Each
 * step above it costs the configured step price per billing period (net).
 * The price is always calculated on the server; values from the browser are
 * only the customer's choice.
 */
final class ProductConfigurator
{
    private const MONEY_RULE = 'nullable|regex:/^\d{1,5}([.,]\d{1,2})?$/';

    /**
     * Resources per category: option name => product config key, factor
     * (option unit → config unit), settings for maximum, step and price, and
     * the hard limit of the option value.
     *
     * @var array<string, array<string, array{config: string, factor: int, max: string, step: string|null, price: string, limit: int}>>
     */
    private const RESOURCES = [
        ProductCategory::VPS => [
            'cores' => ['config' => 'cores', 'factor' => 1, 'max' => 'cfg_max_cores', 'step' => null, 'price' => 'cfg_price_core', 'limit' => 128],
            'memory_gb' => ['config' => 'memory_mb', 'factor' => 1024, 'max' => 'cfg_max_memory_gb', 'step' => null, 'price' => 'cfg_price_memory_gb', 'limit' => 1024],
            'disk_gb' => ['config' => 'disk_gb', 'factor' => 1, 'max' => 'cfg_max_disk_gb', 'step' => 'cfg_disk_step_gb', 'price' => 'cfg_price_disk_step', 'limit' => 65536],
        ],
        ProductCategory::LXC => [
            'cores' => ['config' => 'cores', 'factor' => 1, 'max' => 'cfg_max_cores', 'step' => null, 'price' => 'cfg_price_core', 'limit' => 128],
            'memory_gb' => ['config' => 'memory_mb', 'factor' => 1024, 'max' => 'cfg_max_memory_gb', 'step' => null, 'price' => 'cfg_price_memory_gb', 'limit' => 1024],
            'disk_gb' => ['config' => 'disk_gb', 'factor' => 1, 'max' => 'cfg_max_disk_gb', 'step' => 'cfg_disk_step_gb', 'price' => 'cfg_price_disk_step', 'limit' => 65536],
        ],
        ProductCategory::TEAMSPEAK => [
            'slots' => ['config' => 'slots', 'factor' => 1, 'max' => 'cfg_max_slots', 'step' => 'cfg_slot_step', 'price' => 'cfg_price_slot_step', 'limit' => 1024],
        ],
    ];

    /**
     * Product settings added to a category with configurator.
     *
     * @return array<string, array{label: string, rules: string, default?: mixed, help?: string, type?: string}>
     */
    public static function configFields(string $category = ProductCategory::VPS): array
    {
        if ($category === ProductCategory::TEAMSPEAK) {
            return [
                'configurator' => ['label' => 'Configurator: customers choose the number of slots', 'rules' => 'bool', 'default' => false, 'type' => 'bool', 'help' => 'The slots above are the included minimum; the product price is the price of that minimum.'],
                'cfg_max_slots' => ['label' => 'Configurator: maximum slots', 'rules' => 'nullable|int|min:1|max:1024'],
                'cfg_slot_step' => ['label' => 'Configurator: slot steps', 'rules' => 'nullable|int|min:1|max:256', 'default' => 1],
                'cfg_price_slot_step' => ['label' => 'Configurator: price per additional slot step (net)', 'rules' => self::MONEY_RULE],
            ];
        }

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
     * Option names of a category's configurator (empty = none).
     *
     * @return list<string>
     */
    public static function optionNames(string $category): array
    {
        return array_keys(self::RESOURCES[$category] ?? []);
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function enabled(array $product): bool
    {
        $config = is_array($product['provisioning_config'] ?? null) ? $product['provisioning_config'] : [];

        return isset(self::RESOURCES[(string) ($product['category'] ?? '')])
            && ($config['configurator'] ?? false) === true
            && self::configErrors($config, (string) ($product['currency'] ?? 'EUR'), (string) $product['category']) === [];
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
        $config = $product['provisioning_config'];
        $currency = (string) $product['currency'];
        $ranges = [];
        foreach (self::RESOURCES[(string) $product['category']] as $name => $resource) {
            $min = intdiv((int) ($config[$resource['config']] ?? 0), $resource['factor']);
            $max = ($config[$resource['max']] ?? null) !== null && $config[$resource['max']] !== '' ? max($min, (int) $config[$resource['max']]) : $min;
            $price = trim((string) ($config[$resource['price']] ?? ''));
            $ranges[$name] = [
                'min' => $min,
                'max' => $max,
                'step' => $resource['step'] !== null ? max(1, (int) ($config[$resource['step']] ?? 1)) : 1,
                'price' => $price !== '' ? Money::parse($price, $currency) : 0,
            ];
        }

        return $ranges;
    }

    /**
     * Problems of a product configuration with the configurator switched on
     * (config key => message). Empty when it is off or consistent.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public static function configErrors(array $config, string $currency = 'EUR', string $category = ProductCategory::VPS): array
    {
        if (($config['configurator'] ?? false) !== true || !isset(self::RESOURCES[$category])) {
            return [];
        }
        $errors = [];
        $configurable = 0;
        foreach (self::RESOURCES[$category] as $resource) {
            $value = (int) ($config[$resource['config']] ?? 0);
            if ($resource['factor'] > 1 && ($value % $resource['factor'] !== 0 || $value < $resource['factor'])) {
                $errors[$resource['config']] = 'With the configurator, memory must be whole GB (a multiple of 1024 MB).';
                continue;
            }
            $min = intdiv($value, $resource['factor']);
            $max = $config[$resource['max']] ?? null;
            if ($max === null || $max === '') {
                continue;
            }
            if ((int) $max < $min) {
                $errors[$resource['max']] = 'The maximum must not be lower than the included value.';
                continue;
            }
            $step = $resource['step'] !== null ? (int) ($config[$resource['step']] ?? 1) : 1;
            if ($step > 0 && ((int) $max - $min) % $step !== 0) {
                $errors[$resource['max']] = 'The maximum must be the included value plus whole steps.';
            }
            if ((int) $max > $min) {
                $configurable++;
                if (trim((string) ($config[$resource['price']] ?? '')) === '') {
                    $errors[$resource['price']] = 'Enter a price (0 for free).';
                }
            }
        }
        if ($configurable === 0) {
            $errors['configurator'] = 'Set a maximum above the included value for at least one resource.';
        }
        foreach (self::RESOURCES[$category] as $resource) {
            $value = trim((string) ($config[$resource['price']] ?? ''));
            if ($value === '') {
                continue;
            }
            try {
                if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $value) !== 1) {
                    throw new \InvalidArgumentException('Invalid amount.');
                }
                Money::parse($value, $currency);
            } catch (\InvalidArgumentException) {
                $errors[$resource['price']] = 'Enter an amount such as 1.50.';
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
     * @return array<string, int> option name => value, plus "price"
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

        return $choice + ['price' => $price];
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

        return self::applyChoice((string) ($product['category'] ?? ''), $config, $options) + ['_options' => $options];
    }

    /**
     * Replaces the resource values of a configuration with a choice (option
     * name => value). The order or change validated the values; the bounds
     * only guard against damaged data.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $choice
     * @return array<string, mixed>
     */
    public static function applyChoice(string $category, array $config, array $choice): array
    {
        foreach (self::RESOURCES[$category] ?? [] as $name => $resource) {
            $value = $choice[$name] ?? null;
            if (is_int($value) && $value >= 1 && $value <= $resource['limit']) {
                $config[$resource['config']] = $value * $resource['factor'];
            }
        }

        return $config;
    }

    /**
     * The current choice of a service (from its configuration).
     *
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    public static function choiceOf(string $category, array $config): array
    {
        $choice = [];
        foreach (self::RESOURCES[$category] ?? [] as $name => $resource) {
            $choice[$name] = intdiv((int) ($config[$resource['config']] ?? 0), $resource['factor']);
        }

        return $choice;
    }
}
