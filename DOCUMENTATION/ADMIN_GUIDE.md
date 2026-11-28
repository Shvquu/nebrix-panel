# Administrator Guide

The administration area is available at `/admin` for staff accounts. What a staff member sees and may do depends on the permissions of their role; buttons for actions without permission are hidden and the server rejects them as well.

## First steps after installation

1. Sign in with the account created by `user:create-admin` and enable two-factor authentication (Account → Security).
2. **Settings → General**: company name and address (shown on invoices and e-mails), contact and support e-mail, legal URLs, language, currency, time zone.
3. **Settings → Branding**: logo, favicon, colours, login texts. Changes apply immediately for all visitors.
4. **Settings → E-mail**: SMTP server and sender, then *Send test e-mail*. Without SMTP, e-mails are only written to the log.
5. **Settings → Authentication**: registration, e-mail verification, lockout, **"Require two-factor authentication for staff"** (recommended).
6. **Settings → Billing**: number prefixes, payment term, renewal notice, grace period before suspension, automatic termination (off by default), automatic payment from credit, payment reminders.
7. **Settings → General → Taxes** and **Invoice details**: tax status (small business, reverse charge, OSS, third countries) and the details printed on invoices (address, VAT ID or tax number, bank account, contact person). Have the tax settings checked by your tax advisor – see [Taxes and invoices](#taxes-and-invoices).
8. **Payments**: Stripe/PayPal keys and webhooks – see [PAYMENTS.md](PAYMENTS.md).
9. **Integrations**: add remote systems and run *Test connection* – see [INTEGRATIONS.md](INTEGRATIONS.md).
10. **Products**: create the offers customers can order.
11. **Staff & roles**: create accounts for your team with the least privileges they need.

## Roles and permissions

| Role | Intended for | Default permissions (summary) |
|---|---|---|
| Super Administrator | Owners | Everything, including roles, permissions and other super administrators. Cannot be locked out: the last active super administrator cannot be demoted or suspended. |
| Administrator | Operations | Everything except managing roles and super administrators. |
| Support | Support team | Dashboard, customers (view), services (view/manage), tickets (view/reply/manage), orders, invoices, products and integrations (view). No credit, refunds, settings or staff. |
| Finance | Accounting | Dashboard, orders and invoices (view/manage), transactions, refunds, credit adjustments, customers, products and services (view). |
| Customer | Customers | No admin access. |

* Super administrators can create custom staff roles in **Roles** and tick individual permissions (e.g. a "Support lead" with `staff.manage`).
* Staff managers can only assign roles whose permissions they hold themselves, and cannot edit staff members who have more permissions than they do.
* Nobody can change their own role or status. Changing a member's role signs that member out everywhere.
* Every staff action that changes data is recorded in the **Audit log**.

## Dashboard

Shows customer counts by status, pending orders, active and failed services, unpaid invoices, revenue (current month and total), open tickets, recent transactions and customers, integration health, queue counts, the last scheduler runs and notifications (e.g. failed jobs, unhealthy integrations).

## Customers

* **List and search** by name, e-mail, company, status and group.
* **Customer page**: profile, billing address, last sign-in, number of active sessions, recent sign-ins, services, orders, invoices, transactions and credit history.
* Actions (each requires its own permission and is audit-logged):
  * Edit profile and group, suspend/reactivate the account (suspended customers are signed out).
  * Adjust credit (positive or negative, reason required).
  * Send a password reset link, resend the verification e-mail or mark the e-mail as verified, reset two-factor authentication, revoke all sessions (both also revoke the customer's API tokens).
  * **Close and anonymize** (GDPR erasure): only possible when the customer has no running services; invoices and payments are retained for legal reasons.
* Staff never see or set customer passwords and cannot sign in as a customer.

### Customer groups
Groups provide a percentage discount on orders and renewals and can disable ordering or self-service actions (power actions, password resets) for their members. Products can be restricted to one group.

## Products

* Category: VPS/KVM, LXC container, Dedicated server, Webhosting, Domain, Minecraft/game server, TeamSpeak 3, Nextcloud storage.
* Name, URL slug, description, feature list, price, setup fee, currency, billing cycle (monthly, quarterly, every 6 months, annually, one-time), tax rate, stock (empty = unlimited), visibility and active flags, sort order, optional customer-group restriction.
* **Provisioning**: integration and category-specific settings (e.g. template, CPU, memory, disk, IPv4/IPv6 pools, reinstall templates, backup storage and number of backups for Proxmox; mode (pool, order, server auction), model, location, images and auction limits for Hetzner dedicated servers; service plan for Plesk; TLD for domains; nest/egg/limits for Pterodactyl; slots for TeamSpeak; quota for Nextcloud). All values are validated. For manual fulfilment leave the integration empty and switch off "Provision automatically" (the form does not accept automation without an integration).
* For Hetzner pool products set the **stock** to the number of free pool servers so customers cannot order more than you have.
* Products are not deleted; deactivate or hide them instead. Existing orders and services keep their price snapshot.

### Configurator (KVM, LXC and TeamSpeak)
Tick *Configurator* in the product's provisioning settings to let customers choose CPU cores, memory and disk (KVM/LXC) or the number of slots (TeamSpeak, in slot steps) themselves:

* The product's cores, memory and disk are the **included minimum**; the product price is the price of that minimum (memory must be whole GB).
* Set a maximum and a price per additional step for each resource that may change (cores: per core, memory: per GB, disk: per *disk step*, e.g. 10 GB). A resource without a maximum stays fixed. The form refuses inconsistent settings (maximum below the minimum, missing price, disk maximum that is not the minimum plus whole steps).
* Price = base price + steps × step price (net, per billing period). It is calculated on the server for the order page, the order, the invoice and every renewal; values from the browser are only the customer's choice and are validated (range and step). Customer-group discounts and coupons apply to the configured price.
* The ordered resources are stored with the order item (`cores`, `memory_gb`, `disk_gb` or `slots`) and become the service's configuration; the renewal price is the configured price.
* **Changing resources later**: customers open *Upgrade or downgrade* on the service and move the sliders under *Change resources*. More resources are an upgrade (prorated invoice, applied after payment), fewer resources a downgrade at the end of the paid period – the same flow, limits and withdrawal as package changes. The service keeps its product (no stock is taken or released), a promotion of the original order stays, the disk cannot shrink. Changing to another package of the same upgrade group works as before (the configured price counts for the direction).
* On the website, products with configurator appear on a *Configurator* tab of their product line; the API lists the ranges as `configurator` and accepts the values as order options.

### Product lines, feature sections and sold-out products (website)
* **Product line** (field on the product, e.g. `AMD EPYC 7543 / 7763 / 7713`): products with the same line share a tab on the website – one tab per line and kind (*Configurator*, *Packages*), in the order of the products (sort order, then price). Without a line, all products of a page share one tab.
* **Features**: one per line; a line starting with `#` begins a section on the website (e.g. `# Network`, `# Host system`). The website adds a *Configuration* section from the technical settings (cores, memory, storage, slots …). The shop and the API show the lines without the headings.
* **Sold out** (stock 0): the product stays on the website with a disabled *Sold out* button; if all products of a tab are sold out, the tab shows *Available again soon*. Ordering is impossible (the shop and the order check the stock).

### Promotions
Product form → *Promotion (%)*, start (empty = now) and end (required): orders placed within the period get the discount **permanently** – it is stored with the order item and applies to every renewal (and to later resource changes of configured services, but not to a change to another product). The website shows the promotion banner of the page (“up to X %” when several promotions run, “while stocks last” when stock is limited), *For a short time, permanently*, the reduced price and the saving.

**Previous price (§ 11 PAngV).** The crossed-out *instead of* price is the lowest list price of the 30 days before the promotion started, taken from the price history (every price change of a product is recorded since migration `000019`; the starting point is the price at the time of the update). If the promoted price is not below that lowest price, no reduction is announced – only the price is shown. For configurators the history covers the base price; step prices are not part of it, so do not raise step prices shortly before a promotion. Customer-group discounts and coupons apply on top of the promoted price.

### Domain prices for the first year
Domain products have optional net prices for the **first year of a registration** and of a **transfer** (empty = product price); renewals always cost the product price. Cheaper first years are a one-time discount, more expensive ones a one-time surcharge on the order (shown on the order page, including the total for a transfer). The website and the shop show registration, renewal and transfer prices; a cheaper first year marks the ending as *Sale*.

### Import TLDs (SKRIME)
Admin → Products → **Import TLDs** creates one domain product per top-level domain from the SKRIME price list (permission `products.manage`, an active SKRIME integration is needed):

* Three prices per ending, each = SKRIME's cost + markup (%) + fixed markup, rounded up (to x.99 or x.49/x.99 if chosen), at least the minimum price and never below the cost: the **renewal** (product price, from SKRIME's renewal price), the **first year of a registration** (from the registration price, e.g. offers) and the **first year of a transfer** (from the higher of transfer and renewal price – transfers that do not extend the term, such as `.de`, need a renewal within the paid year). First-year prices equal to the renewal price are not stored. Prices are net; the tax rate is set for all new products.
* *Skip TLDs costing more than* leaves out expensive endings (default 100 EUR); *Only these TLDs* limits the import (e.g. `de com net eu co.uk`).
* **Show preview** lists every ending with SKRIME's prices, your price and the action (new, new price, unchanged, skipped and why). Nothing is changed until you click *Import now*; the price list is loaded again for the import.
* New products: category *Domain*, name `.tld`, address `domain-tld` (dots become dashes), billing cycle *annually*, automatic provisioning through the selected integration, popular endings first (sort order). Existing domain products are never deleted; their price changes only with *Update the price of TLDs that already exist* (only new orders are affected – services keep their renewal price). Endings sold through another integration and addresses already used by another product are skipped. The import can be repeated at any time (e.g. after SKRIME changed prices) and is audit-logged.
* The customer store shows the 12 most popular endings as cards and a search for the others; the website shows a domain search with availability check and a filterable list of all endings.

## Public website
With the website switched on (default), `/` shows a public start page instead of the sign-in page; the customer area stays at `/dashboard` and `/login`. Settings: Admin → Settings → Branding & design → **Public website**.

* **Start page**: headline (default: *Build your project with \*brand\**, words between `*asterisks*` are highlighted) and text, domain search, product overview with the lowest price per product page, feature highlights (all describe functions of the panel), the three order steps, reviews (Trustpilot) and the partner/API section (only parts that are switched on: refer a friend, REST API).
* **Navigation**: *Products* opens the product groups of the customer area (Server, TeamSpeak, Web & domains, More services); groups with several pages open a sub-menu, plus status page and product overview.
* **Product pages** `/products/vserver`, `/kvm`, `/dedicated`, `/gameserver`, `/teamspeak`, `/webhosting`, `/domains`, `/cloud`: only pages with at least one public product appear. Public = active, visible, not reserved for a customer group (sold-out products are shown as sold out). Tabs per product line, package cards with price (incl. VAT; for small businesses final prices with the § 19 UStG note), promotion, *Configuration* and the feature sections; configurators with sliders on the left and the summary (price, saving, order button, configuration) on the right; promotion banner; *Your advantages* from the settings (*Advantages on the … page*: one per line `Title | Text` – only write what you really offer).
* **Ordering** always happens in the customer area: the buttons lead to `/store/<product>` (sign-in or registration first; the chosen configuration or domain is kept). The order page calculates the binding price.
* **Domains**: search for a name (`projekt`) or a domain (`projekt.de`, `www.` and `https://` are removed). The six most popular endings are shown as cards with registration and renewal price; a search checks the name for each of them at the product's registrar (free → *register*, taken → *transfer?*); a domain whose ending is not on a card is checked separately (the longest matching ending wins). At most 60 checks per visitor network and 10 minutes. Below: all endings with registration, renewal and transfer price, search and pages of 10. Umlaut domains need the PHP extension `intl` (otherwise they are rejected).
* **About us** `/about`: shown when the text is set (plain text, blank lines start paragraphs) together with the company address, e-mail and phone from the general settings.
* **Reviews (Trustpilot)**: enter your Trustpilot domain to show the section with links to your Trustpilot page and the review form. Optionally enter the TrustBox *business unit ID* and *template ID* from the TrustBox code in your Trustpilot business account: the widget is then offered as a **two-click** element – nothing is loaded from Trustpilot before the visitor clicks *Show Trustpilot reviews* (mention this in your privacy policy). The Content Security Policy allows `widget.trustpilot.com` only on the start page and only while a TrustBox is configured. The widget itself was not tested against Trustpilot (no account in the development environment) – check it once after entering your IDs.
* The footer links imprint, privacy policy and terms (Settings → General) and the cancellation form. In Germany an imprint is mandatory: set the imprint URL before going live.
* Colours come from the branding (primary colour = accent of the website); the design is dark. The illustration is original artwork (SVG, `resources/views/website/_hero_art.twig`). `robots.txt` allows search engines on `/`, `/products`, `/about` and `/status` only.
* Switch the website off to get the previous behaviour (`/` leads to the sign-in page, the website pages answer 404).

## Orders, invoices and transactions

* **Orders**: status `pending` (unpaid), `paid`, `cancelled`. Unpaid orders can be cancelled (stock is released); orders whose invoice is still unpaid 14 days after its due date are cancelled automatically.
* **Invoices**: order, renewal and credit top-up invoices with status `unpaid`, `paid`, `cancelled`, `refunded`, plus correction documents (type `credit_note`, status `issued`). Actions: cancel an unpaid invoice, record a manual payment (bank transfer), download PDF and XRechnung XML. The invoice page shows the payment event history and related correction documents.
* **Transactions**: all payments and refunds with provider references; refunds are issued here (see [PAYMENTS.md](PAYMENTS.md#refunds)). A full refund suspends the services the invoice paid for. Every refund creates a correction document (credit note) for the refunded amount.
* **Webhook events**: every received payment webhook with its processing status.

## Status page and maintenance announcements

The public page **`/status`** (linked on the sign-in page and in the customer menu; JSON for monitoring tools: `/status.json`) shows the state of your systems, current incidents, scheduled maintenance and the last 14 days. Manage it under *Support → Status page* (permission `status.manage`; the built-in Administrator and Support roles have it).

* **Components** are what customers care about (e.g. "Cloud servers Falkenstein", "Webhosting", "Customer area"), optionally grouped. The shown state is the worst of: the state you set, an incident or ongoing maintenance that affects the component, and – if you link an integration – a failed health check (shown as *degraded*). Hidden components are only visible here.
* **Incidents**: title, impact (minor → degraded, major → partial outage, critical → major outage), affected components, a first message; then post updates (*investigating → identified → monitoring → resolved*). Resolved incidents move to the history.
* **Maintenance**: start and planned end in the panel time zone; announced in advance on the page and on the customer dashboard (72 hours ahead). The scheduler starts it at the start time and completes it at the planned end (with an automatic timeline entry) – extend the end time if the work takes longer, or post *completed* earlier.
* **E-mail notification** (optional, per message): customers with an active service on an integration of the affected components; if no affected component is linked to an integration, all customers with an active service. Mails are queued and sent in the customer's language.
* Entries can be saved as drafts (not published) and deleted; every change is audit-logged.

## Upgrades and downgrades

Give products that customers may switch between the same **upgrade group** (product form, e.g. `lxc-fsn`). Switching is possible between active products with the same group, category, integration, currency and billing cycle; the customer sees the other packages under *My services → service → Upgrade or downgrade* (API: `GET/POST /api/v1/services/{id}/package`).

* **Upgrade** (higher renewal price): the difference is invoiced prorated for the rest of the paid period (invoice type `upgrade`). The package is changed through the queue as soon as the invoice is paid; from then on renewals cost the new price. Unpaid upgrade invoices are cancelled after 14 days. When a renewal invoice is issued while an upgrade still waits for payment, the upgrade is withdrawn (its invoice cancelled) – the customer requests it again after the renewal, priced for the new period.
* **Downgrade**: takes effect at the end of the paid period (no refund for the current period). The next renewal is already invoiced at the new price; until that renewal exists the customer can withdraw the downgrade.
* **Rules**: only active services without a scheduled cancellation and without an open renewal invoice; one change at a time; products reserved for a customer group are only offered to its members; stock of the target product is reserved and the old product's unit released. The customer-group discount applies to the new price; a permanent coupon of the original order only if it is valid for the target product. Whether a change is an upgrade follows the list price, so a larger package is never free at the end of the period.
* **What changes remotely** (only the package values, the installation stays): Proxmox CPU cores, memory, swap (LXC), network rate, backups and disk – disks can only grow, so packages with a smaller disk are not offered as downgrade; VMs apply CPU/memory after the next restart. Pterodactyl memory, swap, disk, CPU, IO, databases, ports, backups (after a restart). TeamSpeak slots. Nextcloud quota. Plesk service plan (switched by plan name). Dedicated servers and domains cannot change their package.
* **Staff** (*Services → service → Package*, permission `services.manage`): switch to any product of the same kind – like a customer (invoice / end of period) or, with `invoices.manage`, at once without invoice. Services without integration only change their billing data; adjust the server yourself. Failed changes (e.g. the integration was unreachable after all retries) show the error and can be retried; a paid upgrade that cannot be applied must be refunded by hand.
* A full refund or chargeback of an upgrade invoice suspends the service (like a renewal); switch the package back by hand if you reactivate it.

## Coupons

*Sales → Coupons* (viewing: `products.view`, creating and editing: `products.manage`).

* **Code**: 3–32 letters, digits, `-` or `_`; stored in upper case, customers may type it in any case. Codes are unique.
* **Discount**: a percentage (e.g. `10` or `12.5`) or a fixed net amount in one currency (fixed coupons only apply to products in that currency). The discount never exceeds the price.
* **Duration**: *first invoice* (price and setup fee of the order) or *first invoice and renewals* – then the service's renewal price is permanently lowered by the same discount (percent of the recurring price, or the fixed amount).
* **Conditions**: valid from/until (whole days), only for selected products, maximum uses in total, uses per customer, only for new customers (no previous order that was not cancelled), active switch.
* Customers enter the code on the order page (*Apply* shows the new price) or send `coupon` with `POST /api/v1/orders`. The rules are checked again inside the order transaction with the coupon locked, so limits hold even for simultaneous orders. A use counts when the order is placed; cancelling the unpaid order (by the customer, staff or the stale-order task) releases it again.
* The discount is part of the invoice line (net amount after discount, the line text names the coupon), so PDF and XRechnung stay valid. The coupon page lists every use with customer, order and discount.
* Deactivate a coupon instead of deleting it – past orders keep their discount.

## Refer a friend

Switch it on under *Settings → General → Refer a friend* ([CONFIGURATION.md](CONFIGURATION.md#refer-a-friend-settings--general)). Customers then find *Refer a friend* in their menu with a personal link (`/r/CODE`) and their code.

* **Assignment**: the link remembers the code for 30 days (cookie) and fills it into the registration form, where the visitor can see and remove it. A code can also be typed in during registration. Signed-in customers and unknown codes are ignored.
* **Qualification**: the referral becomes *qualified* when the referred customer has paid an order or renewal invoice of at least the minimum net amount with real money – Stripe, PayPal or a bank transfer recorded by staff. Payments from account credit and free orders do not count.
* **Reward**: after the holding period (default 14 days, covers the withdrawal period) the referrer receives the reward as account credit, the referred customer the optional welcome credit; both get an e-mail and the credit ledger shows the booking. If the qualifying invoice was refunded or cancelled in the meantime, the referral goes back to *waiting for payment*.
* **Refunds and chargebacks**: payments with a chargeback never qualify. If the qualifying payment is refunded or charged back after the reward, the reward (and welcome credit) is taken back from the credit balances and the referral is marked as rejected.
* **Abuse protection**: sign-ups from an IP address the referrer signed in from within 90 days are held for **review** – *Customers → Referrals* shows them first; *Approve* (`customers.manage`) lets them continue, *Reject* ends them. A monthly limit caps the rewards per referrer (further rewards wait for the next month). A suspended account (either side) waits; closing an account rejects its open referrals.
* Referrers see only date, status and reward of their referrals – never names or e-mail addresses of the referred customers.

## Taxes and invoices

* **Tax decision per invoice** (stored with the invoice: category, country, note): domestic customers and customers without verified VAT ID pay VAT; EU business customers with a VAT ID confirmed by VIES are invoiced net with the reverse charge note; with OSS, EU private customers pay the standard rate of their country; customers outside the EU are invoiced without VAT only if "No VAT outside the EU" is on. With the **small business** switch every invoice is issued without VAT and with the § 19 UStG note.
* **Sole proprietorship ≠ small business**: an Einzelunternehmen charges VAT normally unless it uses the small business rule (§ 19 UStG: previous-year turnover up to 25,000 EUR and current year up to 100,000 EUR since 2025). Leave the switch off unless your tax advisor confirms it.
* The customer's country decides the VAT. After the first paid invoice customers can no longer change it themselves – staff change it on the customer page after checking the new address.
* Customers enter their VAT ID in their profile; it is checked with VIES immediately and re-checked regularly. Reverse charge needs a company name matching the holder VIES reports (legal forms, case and umlaut spelling are ignored) – the profile and the customer page show *verified*, *registered to a different name*, *company name missing*, *not valid* or *not checked yet* together with the registered name. Business customers must give their full address (required for e-invoices). When staff change a customer's VAT ID or country, it is checked again immediately.
* **Documents**: invoices are rendered as PDF and, for business customers, as XRechnung 3.0 XML (UN/CEFACT CII). Both are generated once, archived in `storage/invoices` with a SHA-256 checksum and served unchanged afterwards (the archive is the legal record – back it up and keep it for the statutory 8–10 years). Order and renewal e-mails carry the documents as attachments.
* **Corrections**: issued invoices are never changed or deleted. Cancelling an unpaid invoice creates a cancellation document (prefix `RK-`, type 381 "credit note" in XRechnung) that refers to the original; a refund creates a correction document for the refunded amount (all items for a full refund, one line for a partial refund).
* **Location evidence (OSS)**: for payments by card, SEPA direct debit or PayPal the panel stores the country the provider reports for the payment method (card issuer, bank account, PayPal account) with the invoice. If it differs from the billing country the tax was based on (standard rate or outside the EU; not for reverse charge or small business), the invoice shows a warning and appears under *Invoices → Location evidence → Conflicts to check* (also when the customer has no country, and for credit top-ups compared with the billing address). *Paid without payment country* lists paid invoices without any evidence (credit, manual payments, PayPal webhooks) for your own checks. Check the case (e.g. ask the customer, look at further evidence) and mark it as checked with the result (`invoices.manage`, audit-logged); correct the invoice with a cancellation if the tax was wrong. Credit and manual payments carry no country.
* Validate a sample XRechnung with the official KoSIT validator before you send e-invoices to business customers (see [INTEGRATIONS.md](INTEGRATIONS.md#e-invoices-xrechnung)).

## Cancellations and withdrawals

* Customers cancel through the **"Cancel contracts here"** link (§ 312k BGB) and withdraw through **"Withdraw from contract here"** (EU withdrawal function, mandatory since 19 June 2026). Both links are on every page, also for visitors who are not signed in; the forms work without an account.
* Every declaration is stored with the time of receipt and confirmed to the customer by e-mail immediately (the confirmation is the legal proof of receipt).
* **Ordinary cancellations** of signed-in customers for one of their own services take effect automatically at the end of the paid term (or a later requested date): no further renewal invoices are created and the service is terminated on that date (`services.cancellations` task). The customer can take such a cancellation back until then. Unpaid invoices for the time before the end date stay open, but are neither paid automatically from credit nor reminded – settle them manually.
* Receipts: signed-in customers get the receipt at their account address. For guests the panel sends at most 3 receipts per address and day (and 100 per hour in total); above that the declaration is still recorded and the confirmation page serves as the receipt.
* **Declarations of guests, extraordinary cancellations and withdrawals** appear in **Admin → Cancellations** for review (the identity of a guest cannot be verified automatically). Staff match the declaration to the customer (by account, e-mail or customer number), then *schedule* the end of the service (today or later), *complete* it after handling it manually (e.g. a withdrawal with refund via Transactions) or *reject* it with a note. Rejecting a scheduled declaration removes the end date – the service continues and is renewed. Cancellations scheduled by staff can only be taken back through staff; scheduling a second declaration for the same service replaces the first. Each step is audit-logged.
* Withdrawals: when the customer asked for the service to start during the withdrawal period, a proportionate amount for the time used may be retained – decide this case by case with your legal advisor.

## Customer portal, support PIN and Discord

* The customer dashboard shows a profile card (customer number, credit, customer since), the customer's services grouped by **Server**, **TeamSpeak**, **Web & domains** and **More services** (with order buttons when a group is empty), the support PIN, the number of products, the monthly costs (incl. VAT) and the Discord card. The order menu and the store filter use the same groups; the search field in the top bar searches product names, descriptions and features. The maintenance notice is the dashboard text from Settings → Branding.
* **Support PIN**: when a customer calls, ask for the PIN shown on their dashboard and check it on the customer page (*Support PIN → Check PIN*). Staff never see the PIN.
* **Discord**: see [INTEGRATIONS.md](INTEGRATIONS.md#discord-account-link-and-roles) for the set-up. The customer page shows the linked account, server membership and the last role sync; staff can unlink it.
* Colours and logo come from Settings → Branding (primary = purple, accent = cyan by default; the portal uses a gradient of both).

## Services

* Filter by status and category; search by service name, customer e-mail or service ID.
* Service page: configuration, remote identifiers, live status (where supported), access data (revealing it is audit-logged), invoices, related jobs and the last provisioning error.
* Actions: suspend (with reason; the customer is notified), unsuspend, terminate (deletes the remote resource – confirm carefully), **retry provisioning** for failed services, **activate manually** (for services without automation, optionally storing access data).
* **Remote actions**: the actions the integration offers (power, password reset, reinstall, rescue system, reverse DNS, name servers, Plesk login, …) – the same as for customers, executed and audit-logged in the staff member's name.
* **Console**: graphical console for Proxmox VMs and containers (requires the console proxy, see INSTALLATION.md).
* **Backups**: Proxmox products with a backup storage and Pterodactyl products with a backup limit offer *Create / Restore / Delete backup* (Pterodactyl also *Download*). Customers only see the backups of their own server; restores of Proxmox guests run through the job queue like a reinstall. Terminating a service deletes its customer backups. See [INTEGRATIONS.md](INTEGRATIONS.md#customer-backups).
* Services are suspended automatically when their renewal invoice is unpaid after the grace period and reactivated when it is paid. Termination of long-suspended services happens only if enabled in the billing settings.

## Support tickets

Customers open tickets (optionally about one of their services). Staff reply, change status/priority and assign tickets. Ticket replies notify the customer by e-mail. Messages are shown as plain text.

## Integrations

Add Proxmox clusters, Hetzner Robot accounts, Plesk servers, INWX or SKRIME accounts (domains), Pterodactyl panels, Nextcloud instances and TeamSpeak servers; credentials are stored encrypted and never displayed again. The integration page shows health, the last check, the request log and available resources (Proxmox nodes, Pterodactyl nodes/eggs, TeamSpeak servers, Hetzner servers with their pool state). Disabled integrations are not used for provisioning.

**Proxmox LXC root passwords**: Proxmox has no API for this. To let customers reset the root password of a container without reinstalling, enter the nodes' SSH addresses and host keys on the Proxmox integration, install the script `resources/proxmox/nebrix-lxc-passwd` on every node and add the `authorized_keys` line shown on the integration page (details: [INTEGRATIONS.md](INTEGRATIONS.md#lxc-root-passwords)).

**Hetzner pool workflow**: name spare servers in Robot with the pool prefix (e.g. `nebrix-free-1`). Assigned servers are renamed to `nebrix-service-<id>`. After a termination the server appears as *wipe* (`nebrix-wipe-<number>`). With **Wipe disks of returned servers automatically** (integration settings) the panel wipes it through the rescue system and returns it to the pool by itself; the integration page lists the wipes, their log and a *Retry* button for failed ones, and *Queue waiting servers* adds servers that were waiting before the option was switched on. Without the option wipe the disks yourself, clear the server's firewall in Robot and rename it back to the pool prefix. Details and the wipe methods: [INTEGRATIONS.md](INTEGRATIONS.md#disk-wiping).

**Hetzner server auction**: products in the mode *Buy a matching server from the Hetzner server auction* buy the cheapest auction server within the maximum price, minimum memory/disk, CPU text and location you set on the product (requires *Allow ordering* on the integration; test it with test mode first). Nothing outside the limits is bought – while no offer matches, the order waits in provisioning.

### IP pools
Admin → IP pools: create IPv4 pools and IPv6 pools (network or prefix, gateway, resolvers, optional range; IPv6 pools hand out single addresses, at most 4096 per pool, and accept a link-local gateway such as `fe80::1`) and assign them to VPS/LXC products – one IPv4 and one IPv6 pool per product; without an IPv6 pool guests use SLAAC. The pool page shows every address with its status and service; free addresses can be reserved (and released) with a note. Pools that are used by products or still have assigned addresses cannot be deleted.

## E-mail templates

Admin → E-mail templates lists all system e-mails (registration, verification, password reset/changed, order confirmation, invoice, payment confirmation and reminder, correction document, cancellation receipt and confirmation, service provisioned/suspended/expiring, ticket reply, test). Each template has a subject, an HTML and a text body with `{{ variable }}` placeholders (the available variables are listed next to the editor) in **English and German** – switch the language above the editor. Customers receive the version in their account language; *Reset* restores the default of the selected language. *Preview* renders sample data in a sandbox. Variable values are HTML-escaped.

## Staff, roles and logs

* **Staff**: create staff accounts (an e-mail with a link to set the password is sent), edit name/role/status, reset 2FA or send a password reset.
* **Roles**: super administrators manage custom roles and permissions; the system roles cannot be deleted and the super administrator role cannot be restricted.
* **Login history**: successful and failed sign-ins with IP and user agent.
* **Audit log**: who changed what and when (settings keys, never secret values).
* **API tokens** (permission `api_tokens.manage`): all personal access tokens with owner, scopes, last use and expiry; revoke single tokens or all tokens of a user. Token secrets are never shown – only their prefix (`nbx_1a2b3c4d…`).

## System

* **Settings → System**: maintenance mode (customers see a message; staff can sign in), debug logging, log retention, ticket system on/off, **REST API** (off by default; customer tokens on/off; rate limit per token – see [API.md](API.md#rest-api-v1)). The page also shows PHP version and extensions, database status, pending migrations, the queue and scheduler state.
* **Jobs**: background jobs with status, attempts and last error; failed jobs can be retried or cancelled.
* Updates, backups and server maintenance are done on the server, not in the panel – see [INSTALLATION.md](INSTALLATION.md) and [UPGRADE_GUIDE.md](UPGRADE_GUIDE.md).

## Operational checklist

* Workers and scheduler running? (Dashboard → scheduler runs, Jobs page)
* Failed jobs or provisioning errors? (Jobs, Services → status *failed*)
* Integrations healthy? (Integrations)
* Webhooks arriving? (Payments → Webhook events)
* New cancellations or withdrawals waiting for review? (Cancellations)
* Backups of database, `APP_KEY` and the invoice archive (`storage/invoices`) tested?
