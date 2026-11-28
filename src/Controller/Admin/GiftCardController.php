<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Billing\Money;
use Nebrix\Finance\Csv;
use Nebrix\Finance\GiftCards\GiftCardException;
use Nebrix\Finance\GiftCards\GiftCardService;
use Nebrix\Http\Controller;
use Nebrix\Http\DocumentResponse;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\Encrypter;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

/**
 * Finance → Gift cards (permission gift_cards.manage). New codes are shown
 * once after creation and can be downloaded as CSV for 15 minutes; until
 * then they are kept encrypted in the staff member's session only.
 */
final class GiftCardController extends Controller
{
    private const SESSION_KEY = 'gift_cards.new_batch';
    private const DOWNLOAD_SECONDS = 900;

    public function __construct(
        private readonly GiftCardService $giftCards,
        private readonly SettingsRepository $settings,
        private readonly Encrypter $encrypter,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => in_array($request->string('status'), [GiftCardService::ACTIVE, GiftCardService::REDEEMED, GiftCardService::VOID], true) ? $request->string('status') : '',
            'search' => mb_substr($request->string('search'), 0, 40),
        ];
        $page = $this->page($request);
        $batch = $this->newBatch($request);

        return $this->render($request, 'admin/finance/gift_cards.twig', [
            'result' => $this->giftCards->paginate($filters, $page),
            'filters' => $filters,
            'page' => $page,
            'currency' => $this->giftCards->currency(),
            'new_batch' => $batch !== null && $request->string('batch') === $batch['batch'] ? $batch : null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'value' => 'required|regex:/^\d{1,6}([.,]\d{1,2})?$/',
            'quantity' => 'required|int|min:1|max:' . GiftCardService::MAX_QUANTITY,
            'expires_at' => 'nullable|date',
            'note' => 'nullable|string|max:255',
        ]);
        $currency = $this->giftCards->currency();
        $value = Money::parse((string) $data['value'], $currency);
        $expires = null;
        if ($data['expires_at'] !== null) {
            $zone = new DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
            $expires = (new DateTimeImmutable((string) $data['expires_at'] . ' 23:59:59', $zone));
            if ($expires <= new DateTimeImmutable('now', $zone)) {
                throw ValidationException::withMessages(['expires_at' => 'The expiry date must be in the future.']);
            }
        }
        $user = $this->user($request);
        try {
            $created = $this->giftCards->create($value, $currency, $expires, (int) $data['quantity'], $data['note'] !== null ? (string) $data['note'] : null, $user->id);
        } catch (GiftCardException $exception) {
            throw ValidationException::withMessages(['value' => $exception->getMessage()]);
        }
        // Never the codes themselves in the audit log.
        $this->audit($request, 'gift_card.created', 'gift_card_batch', $created['batch'], ['quantity' => count($created['codes']), 'value' => $value, 'currency' => $currency]);

        $payload = [
            'batch' => $created['batch'],
            'created' => time(),
            'value' => $value,
            'currency' => $currency,
            'expires_at' => $expires?->format('Y-m-d'),
            'note' => (string) ($data['note'] ?? ''),
            'codes' => array_column($created['codes'], 'code'),
        ];
        $request->session()->put(self::SESSION_KEY, $this->encrypter->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), $this->context($request)));
        $this->flash($request, 'success', 'The gift cards have been created. Copy or download the codes now – they are not shown again.');

        return $this->redirect('/admin/gift-cards?batch=' . $created['batch']);
    }

    /** CSV of the codes just created (only in the creating session, only once). */
    public function csv(Request $request, string $batch): Response
    {
        $data = $this->newBatch($request);
        if ($data === null || !hash_equals($data['batch'], $batch)) {
            throw new HttpException(410, 'The codes are no longer available. For security reasons they can only be downloaded right after creation.');
        }
        $request->session()->forget(self::SESSION_KEY);
        $rows = [['code', 'value', 'currency', 'valid_until', 'batch', 'note']];
        foreach ($data['codes'] as $code) {
            $rows[] = [$code, Money::toDecimal($data['value'], $data['currency']), $data['currency'], (string) ($data['expires_at'] ?? ''), $data['batch'], $data['note']];
        }
        $this->audit($request, 'gift_card.exported', 'gift_card_batch', $batch, ['quantity' => count($data['codes'])]);

        return DocumentResponse::make(Csv::build($rows), 'gift-cards-' . $batch . '.csv', 'text/csv; charset=utf-8');
    }

    public function void(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        if ($this->giftCards->void($id, $this->user($request)->id, (string) $data['reason'], $request->clientIp())) {
            $this->flash($request, 'success', 'The gift card has been voided.');
        } else {
            $this->flash($request, 'warning', 'Only unused gift cards can be voided.');
        }

        return $this->redirect('/admin/gift-cards');
    }

    /**
     * @return array{batch: string, created: int, value: int, currency: string, expires_at: string|null, note: string, codes: list<string>}|null
     */
    private function newBatch(Request $request): ?array
    {
        $stored = $request->session()->get(self::SESSION_KEY);
        if (!is_string($stored)) {
            return null;
        }
        try {
            $data = json_decode($this->encrypter->decrypt($stored, $this->context($request)), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
        if (!is_array($data) || (int) ($data['created'] ?? 0) < time() - self::DOWNLOAD_SECONDS || !is_array($data['codes'] ?? null)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return [
            'batch' => (string) ($data['batch'] ?? ''),
            'created' => (int) $data['created'],
            'value' => (int) ($data['value'] ?? 0),
            'currency' => (string) ($data['currency'] ?? ''),
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'note' => (string) ($data['note'] ?? ''),
            'codes' => array_values(array_map('strval', $data['codes'])),
        ];
    }

    private function context(Request $request): string
    {
        return 'session:gift-cards:' . $this->user($request)->id;
    }
}
