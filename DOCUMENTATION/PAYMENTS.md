# Payments

Nebrix bills in invoices. Customers pay an invoice with Stripe, PayPal or their account credit; staff can record manual payments (bank transfer). Invoices with a total of 0.00 (free products, 100 % discounts) are settled automatically.

> **Test status.** Stripe (official `stripe/stripe-php` SDK) and PayPal (REST API v2 over Guzzle) are covered by automated tests with **mocked provider responses** and locally signed Stripe webhooks. They have **not** been exercised against the Stripe test mode or the PayPal sandbox during this modernization because no credentials were available. Run a complete test payment, refund and webhook delivery in sandbox/test mode before enabling live mode.

## Principles

| Rule | Implementation |
|---|---|
| Never trust redirects | The return URL only triggers a server-side lookup (Stripe: retrieve the Checkout Session; PayPal: capture the order). The invoice is marked paid only after the provider confirmed the payment. |
| Server-side amounts | Checkout sessions/orders are created by the server with the invoice's amount and currency. The customer cannot change them. |
| Exact match | A payment is accepted only if amount **and** currency equal the invoice and the provider object references the invoice (Stripe `metadata.invoice_id`, PayPal `custom_id = invoice:<id>`). Mismatches are stored as failed transactions and logged as `payment.amount_mismatch`. |
| Idempotency | Every provider payment/refund reference is recorded once (`UNIQUE (gateway, type, reference)`); webhooks are deduplicated by event ID (`nx_webhook_events`). Return URL and webhook may both arrive – the second one is a no-op. |
| Transactions and locks | The invoice row is locked while a payment is recorded; credit movements lock the customer row. |
| Immutable history | Every step is appended to `nx_payment_events`; nothing updates or deletes these rows. |
| Fulfilment decoupled | Paying an order invoice queues the idempotent job `order.fulfil`; provisioning failures never undo a payment. |
| No card data | Card and PayPal account data never touch the server (hosted Stripe Checkout / PayPal approval page). |

## Stripe

### Setup
1. Admin → Payments → Stripe: enable, choose **Test** mode, enter the publishable key (`pk_test_…`) and secret key (`sk_test_…` or a restricted key `rk_test_…`).
2. In the Stripe dashboard create a webhook endpoint `https://<APP_URL>/webhooks/stripe` with the events:
   * `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`
   * `refund.created`, `refund.updated`, `charge.refunded`
   * `charge.dispute.created`
3. Copy the signing secret (`whsec_…`) into "Webhook signing secret" and save. "Test connection" calls the balance endpoint and reports the key's mode.
4. A restricted key needs write access to Checkout Sessions and Refunds and read access to Balance.

### Flow
1. Customer → `POST /invoices/{id}/pay` (gateway `stripe`) → a Checkout Session in `payment` mode with one line item (invoice number, total, currency) and `metadata.invoice_id`.
2. Stripe redirects to `/invoices/{id}/return/stripe?session_id=…`; Nebrix retrieves the session and settles it if `payment_status = paid` and the amount/currency/invoice match.
3. The webhook `checkout.session.completed` (or `async_payment_succeeded` for delayed methods) does the same and covers customers who close the browser.
4. Webhook signatures are verified with `Stripe\Webhook::constructEvent()` (HMAC-SHA256, 5-minute tolerance). Invalid signatures get HTTP 400 and are not processed.

## PayPal

### Setup
1. Create a REST app in the PayPal developer dashboard (sandbox first). Admin → Payments → PayPal: enable, mode **Sandbox**, client ID and secret.
2. Create a webhook for `https://<APP_URL>/webhooks/paypal` with the events `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED` and `CUSTOMER.DISPUTE.CREATED`.
3. Enter the **Webhook ID** – without it webhooks cannot be verified and are rejected; "Test connection" reports this as degraded.

### Flow
1. Customer → `POST /invoices/{id}/pay` (gateway `paypal`) → Orders API v2 `intent=CAPTURE` with the invoice amount, `reference_id` = invoice number and `custom_id = invoice:<id>`; the customer is redirected to the approval link.
2. PayPal redirects to `/invoices/{id}/return/paypal?token=<order id>`; Nebrix captures the order server-side and settles the capture if it is `COMPLETED` and matches the invoice.
3. Webhooks: `PAYMENT.CAPTURE.COMPLETED` settles (idempotent), `CHECKOUT.ORDER.APPROVED` captures orders whose customer never returned, `DENIED/DECLINED` are recorded as failures.
4. Webhooks are verified with PayPal's `verify-webhook-signature` API using the configured webhook ID; the event body is forwarded unchanged. Unverified events get HTTP 400; if PayPal is unreachable Nebrix answers 503 so PayPal retries.

The OAuth access token is reused until 10 minutes before PayPal's expiry (stored encrypted in `nx_cache`, shared by web, worker and scheduler, keyed by mode and credentials so a new secret never reuses an old token). If PayPal answers 401, the token is discarded and the request repeated once with a new token.

The legacy PayPal IPN endpoint and the deprecated v1 SDK are not used.

## Location evidence
The country of the payment method is stored with the paid invoice (`payment_country`): Stripe reports the card issuer or bank account country of the charge (on the return flow from the expanded session, for webhooks with one extra lookup that never blocks the payment), PayPal the country of the payer's account (return flow; for guest card payments this is the billing address the payer typed, so it is weaker evidence than a card issuer country; captures completed later by webhook carry none). A conflict with the billing country is logged as `tax.location_conflict` and flagged for review – see ADMIN_GUIDE.md, *Taxes and invoices*.

## Account credit

* Customers top up credit with an invoice of type `credit` (Invoices → Top up), paid by Stripe or PayPal; the balance is booked when the payment is confirmed.
* Invoices can be paid with credit when "Allow paying invoices with account credit" is enabled, the invoice is in the default currency and the balance covers the full total. Top-up invoices cannot be paid with credit.
* Staff adjust balances in Admin → Customers → Credit (permission `customers.credit`, reason required, audit-logged).
* Every movement is written to `nx_credit_ledger` with the resulting balance.

## Saved payment methods and automatic payments

Enable *Allow customers to save a payment method for automatic renewal payments* (Admin → Settings → Payment providers → Stripe) and, optionally, *Offer SEPA direct debit as a saved payment method* (SEPA Direct Debit must be activated in the Stripe account; EUR invoices only). Add the webhook events `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_method.detached` and `mandate.updated` to the existing Stripe endpoint.

* **Saving**: Account → Payment methods → *Add card* opens a Stripe Checkout Session in setup mode for the customer's Stripe Customer (created once per account). After the return, the session is retrieved server-side and must belong to the signed-in account (Stripe Customer ID stored in the panel; metadata is checked as well). The panel stores only the Stripe reference, brand, last four digits, expiry, country and – for SEPA – the mandate ID and mandate reference. Up to five methods per account; one is the default.
* **Automatic payment** (customer switch, off by default): unpaid renewal invoices are charged off-session with the default method at the earliest one day before the due date (scheduler task `billing.auto_pay_saved`, hourly). When automatic credit payment is enabled and the credit covers the invoice, the credit is used instead.
* **Never twice**: every attempt is reserved in `nx_autopay_attempts` (unique per invoice and attempt) before Stripe is called and uses the idempotency key `nebrix-autopay-<invoice>-<attempt>`. An attempt interrupted by a network error is repeated with the same key; Stripe returns the original result instead of charging again.
* **Cards**: up to three attempts, three days apart. A bank asking for authentication (3-D Secure) or a decline sends the customer an e-mail with a link to pay manually (templates `autopay_action_required`, `autopay_failed`).
* **SEPA**: the customer first receives a pre-notification with amount, debit date and mandate reference (template `sepa_prenotification`); the debit happens no earlier than *Days between the SEPA pre-notification and the debit* later (default 2 – must not be shorter than the period in the mandate text). SEPA payments are "processing" for a few days and are settled by the `payment_intent.succeeded` webhook; a returned debit (`payment_intent.payment_failed`) is not retried automatically, because return debits cost fees.
* Results are taken from Stripe's API response or verified webhooks only; amount and currency are checked against the invoice like every other payment. Removing a method detaches it at Stripe; a detached method or an inactive mandate is no longer charged.
* Staff see the saved methods and the last attempts on the customer page.

Tested against a mocked Stripe API only (`tests/Feature/SavedPaymentMethodTest.php`); run a test-mode charge with a test card and a test IBAN before going live.

## Manual payments

Admin → Invoices → *Record payment* (permission `invoices.manage`) records bank transfers or other offline payments with a reference. The amount must equal the invoice total; partial payments are not supported.

## Refunds

Admin → Transactions → *Refund* (permission `payments.refund`):

* The amount cannot exceed the remaining paid amount; refunds of one payment are serialised with a database lock.
* Each refund form carries a one-time key that is sent as Stripe `Idempotency-Key` / PayPal `PayPal-Request-Id`, so a double submission refunds only once.
* Stripe/PayPal refunds are recorded when the provider confirms them (immediately or later via webhook). Credit payments are refunded to the credit balance.
* Refunds issued directly in the Stripe or PayPal dashboard arrive by webhook and are recorded once.
* Effects of a refund:
  * **Credit top-up** – the refunded amount is removed from the credit balance. Staff cannot refund credit that was already spent; refunds made in the provider dashboard can make the balance negative (debt).
  * **Order / renewal, fully refunded** – active services of the invoice are suspended (reason "Payment refunded"), services not yet provisioned are cancelled and an unfulfilled order is cancelled. Nothing is terminated automatically.
  * **Partial refunds** (goodwill) leave services running.


**Negative credit after a reversal**: when a refunded or charged-back credit top-up had already been spent, the balance becomes negative. The customer's active services are then suspended and new orders are refused until the balance is settled (credit top-up); reactivate the services afterwards on the customer page.
## Disputes and chargebacks

Stripe `charge.dispute.created`, PayPal `CUSTOMER.DISPUTE.CREATED` and `PAYMENT.CAPTURE.REVERSED` are recorded as `payment.disputed` (or as a refund for reversals). For disputed payments the same reversal rules as for a full refund apply: services are suspended ("Payment disputed"), top-up credit is removed. If you win the dispute, reactivate the services and re-credit the balance manually. Respond to disputes in the provider dashboard.

## Webhook monitoring

Admin → Payments → Webhook events lists every received event with status `received`, `processed`, `ignored` or `failed`. Admin → Transactions shows payments and refunds; the payment event log is shown on each invoice.

## Testing a configuration

1. Stripe test mode: pay an invoice with card `4242 4242 4242 4242`, check that the invoice becomes paid both via return URL and webhook (Stripe CLI: `stripe listen --forward-to <APP_URL>/webhooks/stripe`).
2. Refund partially and fully from the admin panel and from the Stripe dashboard; check invoice status and service state.
3. PayPal sandbox: pay with a sandbox buyer account, check capture, then refund; use the webhook simulator for `PAYMENT.CAPTURE.COMPLETED` (note: simulator events cannot be verified against a real webhook ID – use real sandbox transactions).
4. Only then switch the mode to live and replace the keys.
