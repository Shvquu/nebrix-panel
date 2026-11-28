# Changes – orders area (billing cycles, cart, bundles, add-ons)

Details: `DOCUMENTATION/ORDERS.md`.

## User-visible changes

* **Several billing cycles per product**: products offer additional cycles (quarterly, every 6 months, annually – the existing vocabulary) with a discount in % (product form → *Additional billing cycles*). Customers choose the cycle on the website cards and configurator and on the order page (price per period and per month, saving, cycle discount). Orders, services, renewal invoices, cancellations, package changes and new resources use the service's cycle. Domains stay yearly. The website shows the previous lowest price (§ 11 PAngV) per cycle.
* **Change the billing cycle for the next term** on the service page (applies with the next renewal; can be taken back).
* **Cart** (`/cart`, icon in the customer top bar): several products with their cycle, options, configuration and add-ons; change and remove items; one coupon for the cart; one order and one invoice at checkout; prices recalculated at checkout and compared with the total the customer confirmed. The product page offers *Add to cart* next to the existing direct order (*Order with obligation to pay*). Guests' website selections (cycle, configuration, domain) are carried through sign-in/registration to the product page.
* **Bundles** (Admin → Sales → Bundles): 2–5 products/categories cheaper together (percent or fixed amount, permanent or first period, validity). The best complete bundle is applied automatically, shown as its own line in the cart; it never stacks with promotions/sales on the same items (applies only when it saves more and then replaces them); group discounts and coupons apply on top.
* **Add-ons** (Admin → Sales → Add-ons): IPv4 address, backup storage, priority support or custom extras with a monthly price (charged per service cycle), setup fee, maximum quantity and the products/categories that offer them. Booked with the order or later on the service page (prorated invoice for the rest of the term), renewed as separate invoice lines, cancelled for the end of the term with e-mail confirmation, ended with their service.
* **Add-ons to set up** (Admin → Services): staff queue for add-ons without an automatic provisioner, failed automatic set-ups and ended add-ons to remove by hand.
* REST API: `POST /api/v1/orders` accepts `billing_cycle`; products list `billing_cycles`.

## Migrations, tables and columns

| Migration | Content |
|---|---|
| `2026_11_30_000021_add_billing_cycles` | `nx_products.billing_cycles` (JSON: cycle → discount bp), `nx_product_price_history.billing_cycle` (NULL = own cycle) + index, `nx_services.next_billing_cycle`, `nx_services.next_recurring_amount` |
| `2026_11_30_000022_create_carts` | `nx_carts` (one per user, coupon code), `nx_cart_items` (product, cycle, options with encrypted secrets, configuration, add-ons – no prices) |
| `2026_11_30_000023_create_bundles` | `nx_bundles` (components JSON, discount type/value/currency, permanent, validity, active); `nx_orders.bundle_id`, `bundle_name`, `bundle_discount`; `nx_order_items.bundle_discount` |
| `2026_11_30_000024_create_addons` | `nx_addons` (catalog), `nx_service_addons` (booked add-ons: status, fulfilment auto/manual, details JSON, quantity, prices, order item / booking invoice (unique), cancellation and removal times); `nx_order_items.addon_id`, `parent_item_id` |

Invoice type `addon` (column `nx_invoices.type`, no schema change) for add-ons booked later.

## Settings

Group *Cart & add-ons* on Admin → Settings → General (`config/settings/orders.php`): `orders.cart_max_items` (20), `orders.cart_retention_days` (30).

## Permissions

No new permissions: bundles and the add-on catalog use `products.view` / `products.manage`, the add-on queue `services.view` / `services.manage` (like the other catalog and service pages).

## Routes (`config/routes/orders.php`)

Customer (`auth`, `verified`): `GET /cart`, `POST /cart/items`, `POST /cart/items/{id}`, `POST /cart/items/{id}/remove`, `POST /cart/coupon`, `POST /cart/checkout`, `POST /services/{id}/addons`, `GET|POST /services/{id}/addons/{addon}/cancel`, `POST /services/{id}/addons/{addon}/cancel/withdraw`, `POST /services/{id}/cycle`, `POST /services/{id}/cycle/withdraw`.
Admin (`staff`): `/admin/bundles[...]`, `/admin/addons[...]` (`products.view`), `/admin/addon-queue`, `POST /admin/addon-queue/{id}/done|removed|retry` (`services.view`, actions `services.manage`).

## Scheduler tasks

* `addons.process` (15 min): ends add-ons at their cancellation date, with a terminated/cancelled service or a refunded booking (→ removal by provisioner or staff); cancels add-on booking invoices unpaid for 14 days.
* `carts.prune` (daily): deletes carts unchanged for `orders.cart_retention_days`.

## Jobs

`addon.provision` (`ProvisionAddonJob`), `addon.deprovision` (`DeprovisionAddonJob`) – idempotent, unique keys; registered in `JobRegistry`. `order.fulfil` (`FulfilOrderJob`) now also creates the add-ons of the order.

## Mail templates

`addon_ready` (add-on set up, with details), `addon_cancelled` (cancellation confirmation with receipt time and end date) – English and German.

## Shared files touched

`config/container.php` (binding of `AddonProvisioners` with the marked spot for the Proxmox IPv4 provisioner), `config/mail_templates.php`, `config/mail_templates_de.php`, `src/Scheduler/Scheduler.php`, `src/Provisioning/JobRegistry.php`, `src/Provisioning/FulfilOrderJob.php`, `src/Controller/Admin/SettingsController.php` (settings group), `src/Controller/Customer/ServiceController.php` (add-on/cycle data for the service page), `src/Controller/Admin/ServiceController.php` + `resources/views/admin/services/show.twig` (list of add-ons), `src/Service/ServiceRepository.php` (writable columns), `src/Service/ServiceChangeService.php` (cycle-aware targets and prices, blocker), `src/Api/OpenApi.php`, `src/Api/Resource.php`, `src/Controller/Api/StoreController.php`, layouts `admin.twig` (navigation), `customer.twig` (cart icon), `base.twig` / `website.twig` (`css/orders.css`), `resources/views/customer/services/show.twig` (include), website partials `_plan*.twig`, `_configurator.twig`, `public/assets/js/website.js` (cycle switch, cycle-aware configurator preview).

## Tested only against fakes

* Automatic add-on fulfilment: `tests/Support/FakeAddonProvisioner.php`. No real add-on provisioner is registered; the Proxmox IPv4 provisioner is to be added by its team at the marked line in `config/container.php`.
* Payments in the tests are recorded with the manual gateway (as in the existing billing tests).

## Limitations

* At most one bundle per order (the best); components are matched greedily; a product can appear only once per bundle (use a category for "two of a kind").
* Fixed coupons reduce one item of a cart (the most expensive eligible one); add-ons get no promotions, sales, bundle or coupon discounts.
* A permanent bundle discount stays on each covered service even when another covered service ends.
* The cycle switch keeps the service's discounts proportionally (a fixed "forever" coupon is scaled with the cycle length).
* Website "Order" buttons lead to the product page (where options such as the hostname are entered and the item is added to the cart); they do not add to the cart directly.
* Add-on booking invoices get no payment reminders (the reminder task covers order/renewal/upgrade invoices); unpaid ones are cancelled after 14 days.
* A partial refund of an add-on booking keeps the add-on; a full refund ends it (next `addons.process` run).
