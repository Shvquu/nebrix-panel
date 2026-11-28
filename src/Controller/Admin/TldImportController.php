<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Billing\Money;
use Nebrix\Domain\TldImporter;
use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Integration\Integration;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Integration\IntegrationType;
use Nebrix\Security\ValidationException;
use Nebrix\View\Translator;

/**
 * Admin → Products → "Import TLDs": creates one domain product per TLD from
 * the SKRIME price list. Always previewed first; prices are only changed
 * when staff ask for it.
 */
final class TldImportController extends Controller
{
    private const DEFAULTS = [
        'markup_percent' => '20', 'markup_fixed' => '0.00', 'rounding' => '99', 'min_price' => '4.99',
        'max_cost' => '100.00', 'tlds' => '', 'tax_rate' => '19.00', 'visible' => '1', 'update_prices' => '0',
    ];

    public function __construct(
        private readonly TldImporter $importer,
        private readonly IntegrationRepository $integrations,
        private readonly Translator $translator,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->screen($request, self::DEFAULTS + ['integration_id' => (string) ($this->skrimeIntegrations()[0]['id'] ?? '')], null);
    }

    public function preview(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        [$integration, $options, $input] = $this->input($request);
        try {
            $preview = $this->importer->preview($integration, $options);
        } catch (IntegrationException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->screen($request, $input, null);
        }

        return $this->screen($request, $input, $preview);
    }

    public function import(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        [$integration, $options] = $this->input($request);
        try {
            $result = $this->importer->apply($integration, $options, $this->user($request)->id, $request->clientIp());
        } catch (IntegrationException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/admin/products/tlds');
        }
        $this->flash($request, 'success', $this->translator->translate(':created TLDs created, :updated prices updated, :kept unchanged, :skipped skipped.', array_map('strval', $result)));

        return $this->redirect('/admin/products?category=domain');
    }

    /**
     * @param array<string, string> $input
     * @param array<string, mixed>|null $preview
     */
    private function screen(Request $request, array $input, ?array $preview): Response
    {
        return $this->render($request, 'admin/products/tlds.twig', [
            'integrations' => array_column($this->skrimeIntegrations(), 'name', 'id'),
            'input' => $input,
            'preview' => $preview,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skrimeIntegrations(): array
    {
        return array_values(array_filter($this->integrations->listing(IntegrationType::SKRIME), static fn (array $row): bool => (bool) $row['is_enabled']));
    }

    /**
     * @return array{0: Integration, 1: array{markup_percent: float, markup_fixed: int, rounding: string, min_price: int, max_cost: int, tlds: list<string>, tax_rate_bp: int, visible: bool, update_prices: bool}, 2: array<string, string>}
     */
    private function input(Request $request): array
    {
        $money = 'required|regex:/^\d{1,5}([.,]\d{1,2})?$/';
        $data = $this->validate($request, [
            'integration_id' => 'required|int|min:1',
            'markup_percent' => 'required|regex:/^\d{1,4}([.,]\d{1,2})?$/',
            'markup_fixed' => $money,
            'rounding' => 'required|in:none,99,49',
            'min_price' => $money,
            'max_cost' => $money,
            'tlds' => 'nullable|string|max:20000',
            'tax_rate' => 'required|decimal|between:0,100',
            'visible' => 'bool',
            'update_prices' => 'bool',
        ], [
            'integration_id' => 'SKRIME integration', 'markup_percent' => 'Markup (%)', 'markup_fixed' => 'Fixed markup', 'min_price' => 'Minimum price',
            'max_cost' => 'Skip TLDs costing more than', 'tlds' => 'Only these TLDs', 'tax_rate' => 'Tax rate (%)',
        ]);

        $integration = $this->integrations->find((int) $data['integration_id']);
        if ($integration === null || $integration->type !== IntegrationType::SKRIME || !$integration->enabled) {
            throw ValidationException::withMessages(['integration_id' => 'Choose an active SKRIME integration.']);
        }

        $tlds = [];
        foreach (preg_split('/[\s,;]+/', (string) ($data['tlds'] ?? '')) ?: [] as $tld) {
            $tld = ltrim(mb_strtolower(trim($tld)), '.');
            if ($tld === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $tld) !== 1) {
                throw ValidationException::withMessages(['tlds' => sprintf('"%s" is not a valid TLD.', mb_substr($tld, 0, 70))]);
            }
            $tlds[$tld] = $tld;
        }
        if (count($tlds) > 2000) {
            throw ValidationException::withMessages(['tlds' => 'Enter at most 2000 TLDs.']);
        }

        $options = [
            'markup_percent' => (float) str_replace(',', '.', (string) $data['markup_percent']),
            'markup_fixed' => Money::parse((string) $data['markup_fixed']),
            'rounding' => (string) $data['rounding'],
            'min_price' => Money::parse((string) $data['min_price']),
            'max_cost' => Money::parse((string) $data['max_cost']),
            'tlds' => array_values($tlds),
            'tax_rate_bp' => (int) round((float) $data['tax_rate'] * 100),
            'visible' => (bool) $data['visible'],
            'update_prices' => (bool) $data['update_prices'],
        ];
        $input = [
            'integration_id' => (string) $integration->id,
            'markup_percent' => (string) $data['markup_percent'],
            'markup_fixed' => (string) $data['markup_fixed'],
            'rounding' => $options['rounding'],
            'min_price' => (string) $data['min_price'],
            'max_cost' => (string) $data['max_cost'],
            'tlds' => implode(' ', $options['tlds']),
            'tax_rate' => (string) $data['tax_rate'],
            'visible' => $options['visible'] ? '1' : '0',
            'update_prices' => $options['update_prices'] ? '1' : '0',
        ];

        return [$integration, $options, $input];
    }
}
