# Changes – finance area

Details: [DOCUMENTATION/FINANCE.md](DOCUMENTATION/FINANCE.md).

## User-visible changes

### Admin (new navigation section "Finance")
* **Bank transfers** (`/admin/bank`): import CAMT.053 or bank CSV statements (presets Sparkasse CSV-CAMT V2, DKB, generic; saved column profiles under `/admin/bank/profiles`). Incoming transfers with exactly one open invoice number and exactly the open amount are booked like a manual payment; partial/over-payments, several or unknown numbers and no-longer-open invoices go to a review list (book as payment, optionally with the excess as credit; book as account credit; ignore). Outgoing entries are never booked. Duplicate protection per file and entry.
* **Accounting export** (`/admin/accounting`): DATEV posting batch (EXTF 700/21/13, Windows-1252, CRLF) and CSV exports of documents and payments for a period; lexoffice transfer status, error list and "Sync now".
* **Gift cards** (`/admin/gift-cards`): create codes (shown once, CSV download once within 15 minutes, stored hashed + last 4 characters), list, void.
* **Partners** (`/admin/partners`, `/admin/partners/{id}`): partners and their commissions, payouts (approve, reject, mark paid), SEPA credit transfer file (pain.001.001.09) of approved payouts, suspend partners.
* **Reseller tiers** (`/admin/tiers`): rules (customer group by 12-month revenue or active services, rank), overview, "Evaluate now".
* **Settings → Finance** (`/admin/settings/finance`): groups accounting (DATEV), lexoffice, bank import, gift cards, partner program, reseller tiers.
* Admin → Invoices: filter for the new invoice type *gift card*. Refunds of bought gift cards: only full refunds of unused cards.

### Customer area
* **Gift cards** (`/account/gift-cards`, shown when redeeming is enabled): redeem a code (account credit), buy gift cards (invoice without VAT as multi-purpose voucher; the code is e-mailed after the confirmed payment), list of own cards.
* **Partner program** (`/account/partner`, shown when enabled together with "Refer a friend"): join with consent to the terms, partner link, balances, payout details (IBAN/holder/BIC, encrypted), payout request, commissions, payouts, yearly CSV statement.
* Partners in the partner program receive no referral credit reward (setting, default on); the welcome credit for the referred customer stays.

## Migrations
| Migration | Tables / columns |
|---|---|
| `2026_11_30_000060_create_accounting_sync` | `nx_accounting_sync`; grants the new permissions to the roles `admin` (all five) and `finance` (all but `finance.settings`) |
| `2026_11_30_000061_create_bank_import_tables` | `nx_bank_imports`, `nx_bank_entries`, `nx_bank_csv_profiles` |
| `2026_11_30_000062_create_gift_cards` | `nx_gift_cards` |
| `2026_11_30_000063_create_partner_program` | `nx_partners`, `nx_partner_commissions`, `nx_partner_payouts` |
| `2026_11_30_000064_create_customer_tiers` | `nx_tier_rules`, `nx_customer_tiers` |

All additive. New invoice type `gift_card` (`InvoiceRepository::TYPE_GIFT_CARD`, value in the existing `nx_invoices.type` column). New payment gateway name `bank` in `nx_transactions` (bank import bookings).

## Settings (`config/settings/finance.php`, page "Finance")
* `accounting.*`: `consultant_number`, `client_number`, `chart` (skr03/skr04), `account_length`, `fiscal_year_start`, `lock_bookings`, `debtor_mode`, `debtor_account`, `account_revenue_standard|reduced|reverse_charge|oss|third_country|small_business`, `account_customer_credit`, `bu_keys`, `export_payments`, `account_bank|paypal|stripe`.
* `lexoffice.*`: `enabled`, `api_key` (secret), `auto_sync`, `sync_from`, `category_standard|oss|reverse_charge|third_country|small_business`.
* `bank.*`: `auto_book` (default on), `max_upload_kb` (default 5120).
* `giftcard.*`: `enabled`, `sale_enabled`, `sale_min`, `sale_max`, `validity_months` (36), `tax_note`.
* `partner.*`: `enabled`, `commission_percent` (10), `commission_months` (12), `holding_days` (30), `min_payout` (50.00), `terms_url`, `terms_version`, `replace_referral_reward` (on).
* `tiers.*`: `enabled`, `downgrade_period_days` (90).

## Permissions (`src/Authorization/Permission.php`, group "Finance")
`accounting.export`, `bank.import`, `gift_cards.manage`, `partners.manage`, `finance.settings`. Reseller tiers use the existing `customer_groups.manage`.

## Routes (`config/routes/finance.php`)
* Customer (`auth`, `verified`): `GET /account/gift-cards`, `POST /account/gift-cards/redeem`, `POST /account/gift-cards/buy`, `GET /account/partner`, `POST /account/partner/join`, `POST /account/partner/details`, `POST /account/partner/payout`, `GET /account/partner/statement`.
* Admin (`auth`, `staff` + permission): `/admin/accounting` (+ `/export`, `/lexoffice/sync`); `/admin/bank` (+ `/import`, `/entries/{id}`, `/entries/{id}/assign|credit|ignore`, `/profiles`, `/profiles/{id}/delete`); `/admin/gift-cards` (+ `POST`, `/batch/{batch}/csv`, `/{id}/void`); `/admin/partners` (+ `/{id}`, `/{id}/status`, `/payouts/{id}/approve|reject|paid`, `/payouts/sepa`); `/admin/tiers` (+ `POST`, `/evaluate`, `/{id}`, `/{id}/delete`).

## Scheduler tasks (registered through `Nebrix\Finance\FinanceTasks`)
`accounting.lexoffice_sync` (60 min, only with automatic transfer), `partner.commissions` (60 min), `tiers.evaluate` (daily, only when enabled).

## Mail templates (EN + DE)
`gift_card_purchased` (code after the confirmed payment), `partner_payout_paid`.

## Shared files touched (small additions)
`src/Authorization/Permission.php`, `src/Scheduler/Scheduler.php` (one constructor parameter, one spread line), `src/Controller/Admin/SettingsController.php` (page "finance"), `resources/views/layouts/admin.twig` (Finance section), `resources/views/layouts/customer.twig` (two links), `config/mail_templates.php`, `config/mail_templates_de.php`. Billing/payment code: `InvoiceRepository` (type constant), `InvoiceService::markPaid` (issues the bought gift card), `PaymentService` (gift card reversal on refunds/chargebacks, no location evidence for gift cards), `CreditGateway` (gift cards not payable with credit), `Admin\PaymentController` (gift card refund guard), `Admin\InvoiceController` + `admin/invoices/index.twig` (type filter), `ReferralService` (no credit reward for active partners).

## Tested against fakes / mocks only
* **lexoffice**: tested against a mocked API only (`FakeHttp`); never called against a real lexoffice account.
* **DATEV**: the file structure is tested against the implemented field list; not imported into DATEV during development.
* **Bank CSV presets** (Sparkasse, DKB): built from the published column names, tested with constructed sample files, not with real bank exports.
* **SEPA pain.001.001.09**: structure tested with DOM/XPath; not validated against the XSD nor uploaded to a bank.

## Limitations / open points
* **sevDesk** is not implemented.
* No self-billing credit note (*Gutschrift*) document for partner commissions: the document system only issues documents to customers. Partners invoice their commission and handle VAT themselves; the yearly statement and payout reference support the bookkeeping. The payout amount is the commission as calculated (net of VAT); VAT on commissions of VAT-registered partners must be agreed and handled outside the panel.
* DATEV export: only documents in the default currency; no *Buchungs-GUID*, *Leistungsdatum*, document links or open-item fields; OSS lines cannot be told apart from domestic lines when the rates are equal and OSS is disabled.
* Bank matching only recognises invoice numbers with the current invoice prefix; partial payments cannot be booked on an invoice (existing rule) – they are booked as account credit.
* The uploaded statement file itself is not archived (only its hash and the parsed entries).
* Gift cards are issued in the default currency only (account credit has one currency).
* Reseller tier evaluation loads all active customers in one run (fine for typical sizes; very large installations may want batching).
