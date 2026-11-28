# Orders: billing cycles, cart, bundles and add-ons

This document describes how Nebrix prices and places orders with several billing cycles per product, the cart, bundles and add-ons. All prices are calculated on the server in integer minor units (`Nebrix\Billing\Money`); the browser only previews them.

## Billing cycles

### Model

* `nx_products.billing_cycle` stays the product's **own (default) cycle**; `nx_products.price` is the price of one period of it. Existing products and code keep working unchanged.
* `nx_products.billing_cycles` (JSON, nullable) lists the **additional cycles** with a discount in basis points, e.g. `{"quarterly": 500, "annually": 1500}`. The vocabulary is the existing one: `monthly`, `quarterly`, `semiannually`, `annually` (`one_time` has no other cycles).
* Price of a period in another cycle: the product price scaled to the length of the cycle (rounded half up to minor units) less the cycle discount – `ProductPricing::cyclePrice()`. Example: 10.00 per month, annually −15 % → 120.00 − 18.00 = **102.00 per year**.
* Configurator products: the configured price (product price + steps × step price, per period of the own cycle) is scaled the same way.
* **Domains** and one-time products always have their single cycle (domains are yearly; their first-year registration/transfer prices are unchanged).

**Why a discount and not a price override per cycle?** A discount keeps one source price: configurator step prices, promotions, sales, customer-group discounts and coupons (all percentages) apply consistently to every cycle, the price history stays meaningful and a price change of the product updates all cycles at once. A cycle can never accidentally cost more per month than the default (discount 0–90 %); use the shortest cycle as the product cycle if longer ones should be cheaper.

Admins configure the cycles in the product form (*Additional billing cycles*: tick a cycle, enter its discount in %). The product's own cycle is always offered without discount.

### Where the cycle is used

| Step | Behaviour |
|---|---|
| Store / website | The customer chooses the cycle (radio cards on the order page, a select on the website cards and the configurator). Per cycle: price per period, price per month, saving against the own cycle, cycle discount. Without JavaScript the order page calculates the chosen cycle on the server (GET form). |
| Order item | `nx_order_items.billing_cycle` = chosen cycle, `unit_price` = price of one period of it. A cycle the product does not offer is refused (`This billing cycle is not available for this product.`). |
| Service | `nx_services.billing_cycle`, `recurring_amount` per period of that cycle, `next_due_at` advanced by the cycle. |
| Renewal invoices | One period of the service cycle (`InvoiceService::createRenewal()`), plus add-on lines. |
| Cancellation | Effective at the end of the paid term (`next_due_at`), i.e. of the service's cycle. |
| Up/downgrades | Targets must offer the service's cycle; the new price is the target's price in that cycle; proration uses the length of the service's cycle. |
| New resources (configurator) | Priced in the service's cycle; refused when the product no longer offers that cycle. |
| REST API | `POST /api/v1/orders` takes an optional `billing_cycle`; products list `billing_cycles`. |

### Changing the cycle for the next term

On the service page the customer can choose another cycle of the product for the **next term** (`POST /services/{id}/cycle`, take back: `/cycle/withdraw`). Nothing changes in the paid term: `nx_services.next_billing_cycle` and `next_recurring_amount` are stored and applied when the next renewal invoice is issued (inside the renewal transaction with the service row locked). Add-on amounts are scaled to the length of the new cycle at the same moment.

New price = current renewal price × list price of the new cycle ÷ list price of the current cycle. All percentage discounts the service has (promotion, sale, bundle, customer group, percentage coupon) therefore carry over exactly, the cycle discount applies and nothing is counted twice; a fixed "forever" coupon is scaled with the cycle length.

Blocked while the service is not active, cancelled, the next term is already invoiced, or a package change is open; package changes are blocked while a cycle change is pending.

### Price history (§ 11 PAngV)

`nx_product_price_history` keeps the product price (`billing_cycle IS NULL`) as before and, additionally, the list price of each additional cycle (`billing_cycle = 'annually'` …) whenever it changes (product price, cycle discount, new cycle). `ProductRepository::lowestPriceBefore($id, $moment, 30, $cycle)` returns the lowest price of a cycle in the 30 days before a promotion started; the website shows it per cycle as "instead of". Single-cycle products record exactly what they recorded before.

## Order of discounts (no double-dipping)

Per order line, each discount is calculated once, in this order:

1. **List price** of the chosen cycle (configured price for the configurator; domains: renewal price).
2. **Promotion or global sale** – the higher one (they never add up); a first-period sale tops a permanent promotion up to its own percentage for the first period only. **Or a bundle** that replaces them on its items when it saves more (see below).
3. **Customer-group discount** on the price after 2.
4. **Coupon** on the price after 3 plus the setup fee.
5. Domain first-year price instead of 2–4 for the first year; a first-period discount never makes the first payment negative.

Add-ons: monthly price × months of the cycle − customer-group discount (no promotions, sales, bundles or coupons).

## Cart

* One cart per signed-in customer (`nx_carts`, `nx_cart_items`): product, cycle, order options as entered (secret options such as a transfer auth code encrypted with `APP_KEY`), configurator values, add-ons. **Prices are never stored in the cart.**
* Items are added from the product page (*Add to cart*), changed (*Change* → product page in edit mode, `POST /cart/items/{id}`) and removed. Every item is validated like an order when it is added or changed (options, configurator range, cycle, add-ons, the provisioner's order precheck). The cart page validates again (without the prechecks) and marks items that cannot be ordered (sold out, product no longer available, …).
* **Guests** choose product, cycle and configuration on the website; the order links/forms lead to `/store/{slug}?cycle=…&…`, and the existing "intended URL" logic carries these values through registration and sign-in to the product page, where the item is added to the cart.
* "Buy now" (*Order with obligation to pay* on the product page, `POST /store/{slug}`) still places a one-item order directly.
* **Checkout** (`POST /cart/checkout`): one order with all items and add-ons, one invoice, one coupon. In the transaction: the customer row is locked first (serializes the customer's orders, coupon limits), then all products in ID order (`SELECT … FOR UPDATE`), availability and cycles are checked, stock is checked for all items together and only then taken; every price is calculated again from the locked rows and the coupon row is locked and checked again. The customer confirms the total shown (hidden field); when the recalculated total differs, the order is refused with *The prices in your cart have changed* and the cart shows the new total – client prices are never used. Any failure leaves no order, no stock change and no coupon use. The ordered items are removed from the cart in the same transaction.
* **Coupon on cart level**: the existing rules (validity, total and per-customer limits, new customers only, product list, currency). A percentage coupon reduces every item it is valid for; a fixed amount reduces one item – the most expensive one it is valid for – once per order. One redemption per order.
* Limits: at most `orders.cart_max_items` items; adding/changing items 60/hour, coupon checks 20/hour and orders 10/hour per customer; abandoned carts are deleted after `orders.cart_retention_days`.

## Bundles

Admin → Sales → *Bundles* (`products.view`, changes `products.manage`, audit-logged): name, description, 2–5 components (products and/or categories – each needs its own cart item, e.g. "Domain" twice = two domains), discount (percent 1–90 % of the list prices of the covered items, or a fixed amount in one currency split over them in proportion to their prices, the rounding rest on the most expensive item), permanent (every renewal of the covered services) or first billing period only, validity period, active flag.

* Applied automatically at checkout (and shown in the cart) when the cart contains a complete running bundle. At most **one bundle per order**: the one that saves the customer the most. Components are matched greedily (product components first, then categories, each with the item that gains the most).
* **No stacking with promotions or sales**: a bundle is only applied when its discount is higher than the promotions/sales of the items it covers, and then replaces them on these items (`promo_bp` and `sale_id` stay empty). This is the existing rule "promotions and sales never add up – the higher one applies", extended to bundles. Customer-group discounts and coupons apply on top, as they do on top of promotions. Domains with their own first-year price and add-ons are not part of bundles.
* Stored: `nx_orders.bundle_id`, `bundle_name`, `bundle_discount`; per item `nx_order_items.bundle_discount` (part of `discount`, and of `recurring_discount` for permanent bundles).
* The cart and the order show the bundle as its own line. On the **invoice** the bundle share is included in the lines of the covered items (their description names the bundle) instead of a negative line: EN 16931/XRechnung does not allow negative item prices (BR-27), and the tax stays exact per line.

## Add-ons

### Catalog

Admin → Sales → *Add-ons* (`products.view`, changes `products.manage`, audit-logged): name, description, kind (`ipv4`, `backup_storage`, `priority_support`, `custom`), net price per month and unit, setup fee per unit, currency, tax rate, maximum quantity per service, the products and/or categories that offer it, sort order, active flag. Booked add-ons keep the price they were booked with.

### Booking

* **With the order** (product page, cart, "buy now"): per item `addons[ID] = quantity`. Each add-on becomes its own order item (`product_id` NULL, `addon_id`, `parent_item_id`) and invoice line: monthly price × quantity × months of the cycle, plus setup fee × quantity, less the customer-group discount. After the paid order is fulfilled (`order.fulfil` job, after the services are created) one `nx_service_addons` row per add-on item is created (unique `order_item_id`, idempotent).
* **Later** on the service page (`POST /services/{id}/addons`, confirmation "with costs" required, 10/hour): a prorated invoice of type `addon` for the rest of the paid term (`price per period × remaining time ÷ length of the period ending at the due date`, like package changes) plus the setup fee. The service row is locked during the booking. Not possible while the service is not active, cancelled, or the next term is already invoiced (open renewal). A renewal issued while a booking invoice is unpaid withdraws the booking (cancels its invoice), so a booking at the old period can never be carried into the next term; unpaid booking invoices are cancelled after 14 days.
* Free bookings (price 0) are settled at once like free orders.

### Fulfilment

After a verified payment (`InvoiceService::markPaid()` inside the payment transaction, or the order fulfilment) the add-on is `pending` and the job `addon.provision` runs:

* `Nebrix\Service\Addon\AddonProvisioners` (bound in `config/container.php`, **empty by default**) holds `Nebrix\Service\Addon\AddonProvisioner` implementations; the first whose `supports($kind, $service)` is true sets the add-on up (`provision()` returns the details shown to the customer, e.g. the address). The job waits (retry in 5 minutes) while the service itself is still being set up. Provisioners must be idempotent.
* **The Proxmox IPv4 provisioner is written by another team**: register it at the marked line in `config/container.php` (`>>> Proxmox IPv4 add-on provisioner …`).
* Kinds without a supporting provisioner, and add-ons whose automatic set-up failed after all attempts, appear in Admin → Services → **Add-ons to set up** (`services.view`; actions need `services.manage`, audit-logged): *Mark as set up* (optional details for the customer), *Retry automatic set-up* (failed ones), *Mark as removed* (ended ones).
* The customer gets the e-mail `addon_ready` when the add-on is set up.

### Renewal, cycle change, cancellation, end

* Renewal invoices contain every add-on that is set up (or waiting for staff) and not cancelled before the period as a **separate line** with its own tax rate and the same period as the service line.
* Cancellation (§ 312k-style, like services): *Cancel* on the service page → confirmation page → confirmed cancellation for the **end of the paid term**; the e-mail `addon_cancelled` confirms it immediately with the receipt time and the end date. It can be taken back on the service page until then (unless the next term was already invoiced). An open renewal that already contains the add-on is issued again without it. An unpaid booking is withdrawn instead (its invoice is cancelled).
* The scheduler task `addons.process` ends add-ons whose cancellation date is reached, whose service was terminated/cancelled or whose booking invoice was fully refunded: set-up add-ons go to `removing` (automatic ones are removed by `deprovision()` in the job `addon.deprovision`, manual ones by staff), ones that were never set up are closed.
* A suspended service suspends its add-ons: `AddonService::hasActive()` is false while the service is not active (no remote action for add-ons).

Statuses (`nx_service_addons.status`): `pending_payment` → `pending` → `active` (or `failed` → staff) → `removing` → `removed`; `cancelled` = never paid.

### `AddonService::hasActive(int $userId, string $kind): bool`

True when the customer has an active add-on of the kind on an active service of their own whose cancellation date has not passed – e.g. `hasActive($user->id, 'priority_support')` for ticket priority.

## Security notes

* Prices, discounts, stock, cycles and add-ons are always calculated on the server from database rows; request fields such as `price`, `unit_price` or `setup_fee` are ignored, and the checkout compares the recalculated total with the confirmed one.
* Cart items, service add-ons and services are looked up scoped to the signed-in customer (other IDs answer 404); customer groups without "manage services" cannot book, cancel or change cycles (403).
* Money changes run in transactions with row locks (customer, products in ID order, coupon, service, add-on rows); jobs use unique keys and are idempotent.
* Secret order options in the cart are encrypted at rest (`cart-option:<name>` context) and re-encrypted for the order (`order-option:<name>`).
* No inline scripts or styles: the website reads the prices of every cycle from data attributes rendered by the server (`public/assets/js/website.js`); styles are in `public/assets/css/orders.css`.

## Tested against fakes only

The add-on provisioner path is tested with `tests/Support/FakeAddonProvisioner.php`; no real provisioner is registered yet. Payments in the tests are recorded with the manual gateway.
