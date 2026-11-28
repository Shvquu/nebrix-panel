# Finance

The finance area adds the accounting export (DATEV, CSV, lexoffice), the import of bank statements with automatic matching of transfers to invoices, gift cards, the partner program with cash commissions and payouts, and reseller tiers. Everything is configured under **Admin → Settings → Finance** (permission `finance.settings`) and reached through the **Finance** section of the admin navigation.

> **Tax and accounting rules.** The account assignment, the tax treatment of gift cards and the partner commissions follow common practice for a German business (GoBD, UStG). They are a technical implementation, not tax advice: have the settings and a first export checked by your tax advisor before you rely on them.

## Permissions

| Permission | Allows | Default roles |
|---|---|---|
| `accounting.export` | DATEV/CSV export, lexoffice "sync now" | Administrator, Finance |
| `bank.import` | Import statements, assign/credit/ignore bank entries, CSV profiles | Administrator, Finance |
| `gift_cards.manage` | Create, export and void gift cards | Administrator, Finance |
| `partners.manage` | Partner list, approve/reject/mark paid payouts, SEPA export, suspend partners | Administrator, Finance |
| `finance.settings` | Settings → Finance | Administrator |
| `customer_groups.manage` (existing) | Reseller tier rules and evaluation | Administrator |

Every admin action is written to the audit log (`accounting.*`, `bank.*`, `gift_card.*`, `partner.*`, `tier.*`). Secrets and codes never appear in it.

## Accounting export

*Finance → Accounting export.* Choose a format and a period (local dates of the installation's time zone, at most one year). Nothing is changed by an export.

### Which documents are booked

Issued documents are booked on the day they were issued (accrual basis, *Soll-Versteuerung*): order, renewal and upgrade invoices, credit top-ups, gift card sales and all cancellation/correction documents (`RK-…`). A cancelled invoice therefore appears twice – the invoice and its cancellation document – and the two offset each other. Issued invoices are never changed; corrections exist only as separate documents.

| Document | Booking (DATEV) |
|---|---|
| Invoice (order, renewal, upgrade) | `S` customer account → revenue account of the tax case, gross amount **per tax rate** of the items |
| Cancellation / correction document | same accounts, side `H` |
| Credit top-up, gift card sale | `S` customer account → account for prepaid customer credit (no revenue; VAT arises on the invoices the credit pays) |
| Payment (optional) | `S` clearing account of the payment method → customer account |
| Refund (optional) | `H` clearing account → customer account |

Documents in a currency other than the default currency are left out and reported (DATEV needs an exchange rate for them).

### Tax case and accounts

The tax case comes from the decision stored on each invoice (`tax_category`, `tax_country`). Accounts left empty in the settings use these defaults (automatic VAT accounts where DATEV has them):

| Tax case | Rule | SKR 03 | SKR 04 | DATEV fields 40/41 |
|---|---|---|---|---|
| Domestic, standard rate | category S, home country or no country | 8400 | 4400 | – |
| Domestic, reduced rate | category S, rate below the home standard rate | 8300 | 4300 | – |
| EU reverse charge | category AE | 8336 | 4336 | customer's VAT ID (EC Sales List) |
| EU consumers (OSS) | category S, other EU country and OSS enabled (or the line carries that country's rate) | 8320 | 4320 | country, rate (e.g. `FR`, `20,00`) |
| Outside the EU | category O | 8338 | 4338 | – |
| Small business (§ 19 UStG) | category E | 8195 | 4185 | – |
| Prepaid customer credit | top-ups, gift cards | 1710 | 3250 | – |
| Bank (manual payments, bank import) | | 1200 | 1800 | |
| PayPal / Stripe clearing | | 1360 | 1460 | |

* **BU keys** are empty by default (automatic accounts). For accounts without automatic VAT enter them in *BU keys per tax case*, e.g. `reverse_charge=94, oss=240`.
* **Account length** (*Sachkontenlänge*, default 4): default accounts get trailing zeros for longer lengths; accounts you enter must have exactly that length.
* **Customer accounts**: one debtor per customer (*base + customer number*, default `10000 + id` = `10042` for customer 42) or one collective debtor. Debtors must lie in the DATEV debtor range (10000–69999 for account length 4); otherwise the export is refused with a message.
* **Fiscal year**: a posting batch must lie within one fiscal year (*First month of the fiscal year*); the header carries its start date.

### DATEV file format (EXTF)

`EXTF_Buchungsstapel_<from>_<to>.csv` – DATEV format version 700, data category 21 "Buchungsstapel", format version 13; semicolon separated, CRLF line endings, Windows-1252 (characters without an equivalent become `?`), decimal comma without thousands separators, text fields in double quotes (double quotes inside a text are replaced by `'`).

Header (line 1, 31 fields) – filled: 1 `"EXTF"`, 2 `700`, 3 `21`, 4 `"Buchungsstapel"`, 5 `13`, 6 creation time `YYYYMMDDHHMMSSfff`, 8 origin `"NX"`, 9 exported by (staff member's name, max. 25), 11 consultant number, 12 client number, 13 start of the fiscal year `YYYYMMDD`, 14 account length, 15/16 period `YYYYMMDD`, 17 description (max. 30), 19 booking type `1` (financial accounting), 20 accounting purpose `0`, 21 *Festschreibung* (`0`/`1`, setting), 22 currency, 27 chart of accounts (`"03"`/`"04"`). The other header fields are empty.

Line 2 contains the 125 column names of format version 13. Booking lines have 125 fields; filled are only:

| No. | Column | Content |
|---|---|---|
| 1 | Umsatz (ohne Soll/Haben-Kz) | gross amount, always positive (`119,00`) |
| 2 | Soll/Haben-Kennzeichen | `"S"` or `"H"` |
| 3 | WKZ Umsatz | currency (`"EUR"`) |
| 7 | Konto | customer account, for payments the clearing account |
| 8 | Gegenkonto (ohne BU-Schlüssel) | revenue/credit account, for payments the customer account |
| 9 | BU-Schlüssel | from the setting (usually empty) |
| 10 | Belegdatum | `TTMM` (local date of the document or payment) |
| 11 | Belegfeld 1 | document number (only `A–Z a–z 0–9 $ & % * + - /`, max. 36) |
| 14 | Buchungstext | "Rechnung …"/"Gutschrift/Storno …"/"Zahlung …" with the customer name (max. 60) |
| 40 | EU-Land u. UStID (Bestimmung) | reverse charge: VAT ID of the customer; OSS: country code |
| 41 | EU-Steuersatz (Bestimmung) | OSS: rate of the country (`20,00`) |
| 114 | Festschreibung | `0`/`1` (setting) |

Not filled: exchange rates, *Belegfeld 2*, document links, cost centres, *Leistungsdatum*, *Buchungs-GUID* and the open-item fields. The file structure is tested against this field list (`tests/Feature/Finance/AccountingExportTest.php`); an import into DATEV itself was not possible during development – import a first file into a test client together with your tax advisor.

### Plain CSV

*CSV: invoices and correction documents* and *CSV: payments and refunds*: UTF-8 with BOM, semicolon, CRLF, decimal point (`12.50`), one row per document/transaction. Text that a spreadsheet would run as a formula (starting with `=`, `+`, `-`, `@`) is prefixed with `'`.

### lexoffice

*Settings → Finance → lexoffice*: enable, store an API key (encrypted, write-only) and optionally *Transfer automatically (every hour)*. *Sync now* on the accounting page transfers pending documents at once (also those that failed ten times).

* Transferred: order, renewal and upgrade invoices and their correction documents in EUR, issued on or after *Only documents issued on or after*. Each becomes a voucher (`salesinvoice` / `salescreditnote`, gross amounts per tax rate, collective customer contact) with the archived PDF attached. Credit top-ups and gift cards are not revenue and are not transferred.
* Posting categories per tax case are settings (domestic default "Einnahmen" `8f8664a8-fd86-11e1-a21f-0800200c9a66`); reverse charge, OSS, outside-the-EU and small-business invoices need their category ID from lexoffice (`GET /v1/posting-categories`) – without it the document fails with an error before anything is sent.
* **Idempotent**: the voucher ID is stored per document (`nx_accounting_sync`); a voucher is created at most once. If an answer was lost, the next attempt looks the voucher number up (`GET /v1/voucherlist`) before creating it again; a failed PDF upload is repeated without a new voucher. Errors are listed on the accounting page (status, attempts, last error; the API key never appears).
* About two requests per second (lexoffice limit); one transfer at a time (database lock).
* **Tested against a mock only.** The requests follow the public lexoffice API documentation (vouchers, voucher files, voucher list); they have not been sent to a real lexoffice account. Test with a lexoffice test account before enabling the automatic transfer.
* **sevDesk** is not implemented.

## Bank statement import

*Finance → Bank transfers* (permission `bank.import`).

### Formats

* **CAMT.053** (`camt.053.001.02` and later versions, detected by the namespace). Only booked entries (`BOOK`) are read; batch entries with several transactions become one entry per transaction. Read: amount, credit/debit, booking and value date, bank reference (`AcctSvcrRef`), remittance (`Ustrd`, structured reference, end-to-end ID), payer/payee and IBAN.
* **Bank CSV** with a column mapping: built-in presets for *Sparkasse (CSV-CAMT V2)*, *DKB (since 2023)* and a generic layout, plus profiles saved under *CSV profiles* (delimiter, encoding UTF-8/Windows-1252/ISO-8859-1, date format, decimal separator, column names; several reference columns separated by `|`; optional status column such as "Gebucht"). The header row is found automatically (preamble lines are skipped). The presets follow the column names the banks publish – check them with a sample file of your account.

**Safety**: files are limited in size (*Maximum statement file size*, default 5 MB) and detected by content (XML/text only); the uploaded file is not stored, only its SHA-256 and the parsed entries. XML with a document type declaration (entities, external DTDs – XXE and "billion laughs") is refused before parsing; the parser runs with `LIBXML_NONET` and without entity substitution.

### Matching

For every **incoming** entry the remittance text is searched for invoice numbers of the current prefix; spaces, dashes, dots, slashes and missing leading zeros are tolerated (`NX 2026 123`, `nx2026000123`, `NX-2026-000123`).

| Situation | Result |
|---|---|
| Exactly one invoice number, invoice open, same currency, amount = invoice total | **Booked automatically** (if *Book clear matches automatically* is on; otherwise "clear match – please confirm") |
| Amount below / above the invoice total | Review: *partial payment* / *more than the invoice total* |
| Several invoice numbers | Review: *several invoice numbers* (suggestion: the first open one) |
| Invoice already paid or cancelled | Review (possible double payment) |
| No invoice number, unknown number, other currency, bank reversal | Review |
| **Outgoing** entry | Listed as *outgoing*, never booked |

A booking goes through `PaymentService::recordPayment()` with gateway `bank`, exactly like a manual payment recorded by staff: the invoice is paid, the customer gets the payment confirmation, orders are fulfilled and renewals extend the service. The invoice and the bank entry are locked during the booking; an invoice paid in the meantime (e.g. by card) is never paid twice.

### Review actions

* **Book as invoice payment** – enter the invoice number. The amount must equal the invoice total; with *Book an amount above the invoice total as account credit* an over-payment pays the invoice and the difference becomes credit (credit top-up invoice paid by the same transfer).
* **Book as account credit** – customer number or e-mail address: a credit top-up invoice is issued for the whole amount and paid by the transfer (partial payments, transfers without reference). The credit can then pay invoices.
* **Ignore** with a reason (e.g. not a customer payment); also for outgoing entries.

### Duplicates

A file is imported only once (SHA-256 of the content). Each entry is stored once: by the bank reference (`AcctSvcrRef` / CSV reference column) when available, otherwise by its content plus the number of identical entries before it in the same file – two identical transfers on one day stay two entries, an overlapping statement does not import them again. The payment reference `bank-<entry hash>` is unique in `nx_transactions` as a second safeguard.

## Gift cards

*Finance → Gift cards* (permission `gift_cards.manage`); redeeming must be switched on under *Settings → Finance → Gift cards*.

* **Creating**: value (default currency – account credit is kept in it), quantity (1–500), optional expiry date, internal note. Codes have 16 characters from a 32-character alphabet without confusable characters (80 bits from `random_bytes`), shown as `XXXX-XXXX-XXXX-XXXX`. **Only the SHA-256 hash and the last four characters are stored.** The codes are shown once after creation and can be downloaded **once** as CSV within 15 minutes; until then they are kept encrypted in the creating staff member's session only. The audit log records batch, quantity and value, never codes.
* **Voiding**: unused cards can be voided with a reason.
* **Redeeming** (*Account → Gift cards*): the value is booked as account credit through `CreditService` (credit ledger entry "Gift card ****ABCD"). Single use under a row lock. **Brute-force protection**: 10 attempts per customer and 30 per network (IPv4 address / IPv6 /64) and hour, counted before the code is checked – above the limit even a correct code is not evaluated. Unknown, used and void codes give the same message; every attempt is audit-logged.
* **Expiry**: in Germany a voucher should be valid for at least the regular limitation period (three years to the end of the year); shorter periods in your terms may be invalid. Bought cards are valid for *Validity of bought gift cards* (default 36 months).

### Buying gift cards

With *Customers can buy gift cards* customers choose a value between the configured minimum and maximum and receive an invoice of type `gift_card`.

* **VAT**: a gift card for account credit that can be used for every product is a **multi-purpose voucher** (§ 3 Abs. 15 UStG). Its sale is not a taxable supply; VAT arises when the credit pays an invoice, and those invoices carry it as usual (including OSS or reverse charge of the redeeming customer). The gift card invoice is therefore issued without VAT, tax category **O** (not subject to VAT; XRechnung with the note as exemption reason) and the note from *Note on gift card invoices*. In the DATEV export it is booked to the account for prepaid customer credit, like a credit top-up.
* The code is created and e-mailed (template `gift_card_purchased`, in the buyer's language) **only when the payment is confirmed** – by the provider (webhook or server-side check) or recorded by staff/bank import – inside `InvoiceService::markPaid`. A return from the payment page alone does nothing. The buyer's credit is not touched; the code can be given to anybody. The e-mail is the only copy of the code.
* Gift cards cannot be paid with account credit (credit cannot buy credit).
* **Refunds and chargebacks**: a refund or chargeback of the purchase voids an unused card; if it was already redeemed, its value is taken back from the redeeming account (the balance may become negative, which blocks new orders until settled). Staff refund only unused cards and only in full.

## Partner program

Switch it on under *Settings → Finance → Partner program*; it needs *Refer a friend* (the partner link is the referral link).

* **Joining**: customers open *Account → Partner program* and accept the partner terms (URL and version from the settings; consent time, IP and version are stored).
* **Commission**: for every order, renewal or upgrade invoice of a customer they referred that is **paid with real money** (Stripe, PayPal, bank transfer/manual – not account credit, not free orders) within *N months after the referred customer's sign-up* (and after the partner joined), the partner earns the configured percentage of the **net** amount. Referrals held for review (sign-up from the partner's own network) or rejected earn nothing. With *Partners receive no credit reward* (default) partners get no referral credit; the referred customer still receives the welcome credit.
* **Holding period** (default 30 days): commissions are *pending* and become *payable* afterwards.
* **Reversal**: when the invoice is refunded, cancelled or charged back, the commission follows – entries still pending or payable are cancelled (full refund) or reduced by a negative entry (partial refund, proportional to the refunded share); commissions already requested or paid out get a negative entry that is set off against the next payout. Commissions are checked again right before a payout is requested.
* **Payout details**: IBAN (validated by country length and mod-97 check digits, SEPA countries), account holder, optional BIC – **encrypted at rest** and bound to the partner; only the last four characters are shown. Every payout keeps its own encrypted snapshot, so a later change never redirects a requested payout.
* **Payout**: partners request a payout of everything payable once it reaches the minimum (one open payout at a time). Staff (`partners.manage`) **approve** it, transfer the money (by hand or with the **SEPA credit transfer file**, pain.001.001.09, of all approved payouts not exported yet; debtor = IBAN/BIC/holder from *Settings → General → Invoice details*), then **mark it paid** with the bank reference – or **reject** it (the commissions become payable again). Staff cannot process their own payout. The partner is e-mailed when it is paid (`partner_payout_paid`).
* **Statements**: partners download a yearly CSV statement of their commissions, reversals and payouts. Partners never see who their referred customers are or their invoice numbers – only date, net amount, rate and commission.
* **VAT and invoices**: the commission is a service of the partner to you. The partner has to invoice it (or you agree on a self-billing credit note, *Gutschrift* under § 14 Abs. 2 Satz 2 UStG) and handles VAT and income tax; for partners who are businesses with VAT, the payout amount must include their VAT according to your agreement. **The panel does not create that document**: the existing document system issues invoices *to* customers, not credit notes *from* suppliers; use the statement and the payout reference for your bookkeeping.

## Reseller tiers

*Finance → Reseller tiers* (permission `customer_groups.manage`); evaluation must be switched on under *Settings → Finance → Reseller tiers*.

* Create the customer groups first (e.g. Bronze 5 %, Silver 10 %, Gold 15 % discount), then rules: group, condition (*paid net revenue of the last 12 months* – order, renewal and upgrade invoices in the default currency, refunds deducted proportionally – or *number of active services*), threshold and rank. The best rule (highest rank) a customer meets wins.
* The daily task `tiers.evaluate` (or *Evaluate now*) changes the group of active customers: **upgrades at once, a downgrade at most once per period** (*At most one downgrade per … days*, default 90). Only customers without a group or in a group used by an active rule are changed; groups assigned by hand for other reasons stay. A group changed by staff during the evaluation is not overwritten. Every change is audit-logged (`tier.upgraded` / `tier.downgraded` with the metrics); the overview shows each customer's tier, revenue, services and last changes.
* The group discount applies to new orders and renewals as usual.

## Scheduler tasks

| Task | Interval | Does |
|---|---|---|
| `accounting.lexoffice_sync` | 60 min | transfers pending documents to lexoffice (only with *Transfer automatically*) |
| `partner.commissions` | 60 min | reverses commissions of refunded/cancelled/charged-back invoices, accrues new ones, releases them after the holding period (reversal also while the program is off) |
| `tiers.evaluate` | daily | evaluates the reseller tiers (only when enabled) |

## Tables

| Table | Content |
|---|---|
| `nx_accounting_sync` | transfer state per document and provider: status, external voucher ID, PDF uploaded, attempts, last error |
| `nx_bank_imports` | imported files: format, profile, name, SHA-256 (unique), account, statement ID, counts |
| `nx_bank_entries` | entries: hash (unique), bank reference, dates, direction, amount, currency, payer, IBAN, remittance, status (`matched`, `assigned`, `credited`, `review`, `ignored`, `debit`), reason, invoice, transaction, note, staff, time |
| `nx_bank_csv_profiles` | saved CSV column mappings |
| `nx_gift_cards` | SHA-256 of the code (unique), last four characters, batch, value, currency, status (`active`, `redeemed`, `void`), source (`admin`, `purchase`), purchase invoice (unique), buyer, expiry, note, redemption and voiding |
| `nx_partners` | partner per customer: status, terms version, consent time and IP, encrypted IBAN and holder, BIC, last four IBAN characters |
| `nx_partner_commissions` | commission entries: partner, referral, invoice, kind (`accrual`, `adjustment`), unique accrual key, net basis, rate, amount, status (`pending`, `payable`, `requested`, `paid`, `reversed`), payable from, payout |
| `nx_partner_payouts` | payouts: amount, status (`requested`, `approved`, `paid`, `rejected`), encrypted snapshot of the payout details, reference (unique, end-to-end ID), staff and times, bank reference, SEPA export time |
| `nx_tier_rules` | tier rules: name, group, metric, threshold, rank, active |
| `nx_customer_tiers` | tier state per customer: rule, group, metrics, last change, last downgrade, last evaluation |

Invoices get the new type `gift_card` (`nx_invoices.type`, no schema change).
