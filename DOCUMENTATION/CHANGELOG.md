# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [2.0.0-dev] – unreleased

Complete modernization of the RED-Host panel as **Nebrix**. The legacy PHP 7 application (only present in the git history) was audited and replaced; see [AUDIT.md](AUDIT.md) for findings and [UPGRADE_GUIDE.md](UPGRADE_GUIDE.md) for the migration.

### Added
- PHP 8.3/8.4 code base with Composer, PSR-4 autoloading, strict types, dependency injection container, HTTP kernel, router and middleware pipeline.
- MariaDB/MySQL schema with forward-only migrations (`nx_` tables next to legacy tables) and CLI (`bin/nebrix`).
- Authentication: Argon2id, password policy, e-mail verification, password reset, TOTP two-factor authentication with recovery codes, login history, account lockout and rate limiting, database sessions with hashed IDs.
- Role-based access control with Super Administrator, Administrator, Support, Finance, Customer and custom staff roles; audit log.
- Admin panel: dashboard, general/authentication/billing/branding/e-mail/system settings, logo and favicon upload, customers and customer groups, products for VPS/KVM, LXC, dedicated servers, webhosting, domains, Minecraft/game servers, TeamSpeak 3 and Nextcloud, orders, invoices, transactions and refunds, services, support tickets, integrations, e-mail templates, staff, roles, login history, background jobs.
- Customer panel: dashboard, store and ordering, services with live status and power actions, access data, orders, invoices and payments, credit top-up, support tickets, profile, security settings (password, 2FA, sessions), English and German.
- Integrations with encrypted credentials, SSRF protection and health checks: Proxmox VE (API tokens, KVM clone with cloud-init, LXC, multi-cluster), Pterodactyl, Nextcloud, TeamSpeak 3 ServerQuery.
- Payments: Stripe Checkout (official SDK) and PayPal Orders v2 with verified webhooks, account credit, manual payments, refunds and dispute handling, immutable payment event log, idempotent processing.
- Database job queue with retries/back-off and a scheduler for renewals, overdue suspension, optional termination, stale order cancellation, health checks and data retention.
- `legacy:import` for RED-Host customer accounts and balances.
- Docker image (PHP 8.4 + Apache, non-root), docker-compose with worker and scheduler, GitHub Actions CI (PHP 8.3/8.4, PHPStan, PHPUnit, composer audit).
- Test suite (unit, integration, feature) with mocked integrations and payment providers.
- Documentation set in `DOCUMENTATION/`.

### Added (infrastructure features)
- Dedicated servers through the **Hetzner Robot** API: assignment from a server pool or ordering (opt-in, test mode by default), installation, firewall-based suspension, rescue system, resets, reverse DNS, reinstall, wipe queue on termination, optional cancellation.
- **Proxmox**: IP pools with static IPv4 addressing (admin UI), reinstall with selectable templates, root password reset (guest agent / cloud-init), graphical **noVNC console** through the new `console:proxy` WebSocket relay.
- **Plesk** webhosting (customers, subscriptions, suspension, single sign-on, password reset) and **INWX** domain registrations and transfers (prechecks before payment, registrant contacts, name servers, auth codes, renewal modes) behind a registrar interface.
- Service actions with input fields and confirmation, staff remote actions, reinstall flow through the provisioning queue.
- `legacy:import --only=services,tickets,history` for RED-Host services (Proxmox, TeamSpeak, Pterodactyl, Plesk), tickets and payment history.
- Optional CAPTCHA (hCaptcha, Cloudflare Turnstile) on registration and password reset; `db:triggers` for append-only tables.

### Added (REST API)
- Token-authenticated REST API under `/api/v1` with an OpenAPI 3.1 document (`/api/v1/openapi.json`): account, products, order placement, services (status, actions, access data), invoices and payment start, support tickets; admin endpoints for customers, products, orders, invoices, service lifecycle and ticket replies.
- Personal access tokens (Account → API tokens): scopes, expiry, IP allowlist, shown once, stored hashed; admin overview and revocation (permission `api_tokens.manage`); settings to enable the API, allow customer tokens and set the rate limit.
- Stateless request handling for API routes (no session/CSRF, JSON errors), per-user rate limits, `Idempotency-Key` support for `POST` requests.
- Security review of the API; all findings fixed: single sign-on needs `services:credentials` and is never stored for replays, tokens are revoked on password change/reset, "sign out everywhere" and 2FA reset and their creation is e-mailed, scope checks before idempotent replays, write scopes require the view permission, suspension e-mails by default, internal suspension notes hidden from customers, customer tokens blocked while customer logins are disabled, rate limit per user plus a limit for live data, robust idempotency hashing and abandoned keys, anonymised token rows on account closure, super administrators' tokens protected. List-valued input for single-value fields is now a validation error instead of a server error (web and API).

### Added (billing, contracts and backups)
- **Automatic renewal from credit**: renewal invoices are paid from account credit on the due date (setting + per-customer opt-out); configurable **payment reminders** before/after the due date.
- **E-mails in the recipient's language**: every template in English and German, editor with language switch; customers receive their account language.
- **Cancellation button (§ 312k BGB) and withdrawal function** (EU Directive 2023/2673, since 19 June 2026): public forms linked on every page, immediate receipt e-mail, automatic end of the service at the end of the paid term for signed-in customers, review queue for guests/extraordinary cancellations/withdrawals (Admin → Cancellations), take-back by the customer, API endpoints.
- **EU VAT**: tax decision per invoice (standard, reverse charge with VIES-verified VAT ID, OSS rates per country, small business switch for § 19 UStG, optional exemption outside the EU), VAT ID check on profile changes and daily re-check, invoice tax notes.
- **Invoice documents**: PDF invoices (dompdf), XRechnung 3.0 (UN/CEFACT CII) for business customers, attachments in order/renewal e-mails, unchangeable archive with SHA-256 checks, cancellation and correction documents for cancelled invoices and refunds (prefix `RK-`), downloads for customers, staff and the API.
- **Customer backups**: Proxmox (vzdump to a backup storage, restore through the provisioning queue, deletion, cleanup on termination) and Pterodactyl (create, restore, download, delete) with per-product limits and strict ownership checks.

### Added (Plesk)
- SMTP: option to skip certificate verification for a mail server on the same machine (Plesk without a domain certificate; refused for remote hosts), and the SMTP connection test and test e-mail show the server's answer instead of a generic error.
- `.env.example` no longer forces `SESSION_SECURE_COOKIE=true`; it follows `APP_URL`, so installations on a plain-HTTP IP address can sign in.
- Installation guide: running on an IP address without a domain, Plesk mail server settings.
- Installation guide for Plesk (INSTALLATION.md, option C): document root, PHP handler, Composer, scheduled tasks for scheduler and worker, e-mail, console proxy, updates and backups.
- `public/uploads/.htaccess` blocks scripts, HTML and SVG in the upload folder on Apache hosts without the Docker configuration.

### Added (customer portal and Discord)
- Redesigned customer portal in the Nebrix colours (purple, blue, cyan; dark and light mode): grouped sidebar with icons and order menu by product group, product search in the top bar, profile card with customer number and credit, services in tabs by product group with order buttons, key figures (support PIN, products, monthly costs incl. VAT), Discord card; store filter by group and category.
- **Discord account link** (OAuth2) with optional server join and automatic roles for linked customers and customers with an active service (queue job, periodic re-sync, only panel-granted roles are removed); unlink by customer or staff; admin settings and status on the customer page.
- **Support PIN** for identity checks by phone or chat (encrypted, verified by staff without seeing it, renewable).
- Account closure now also removes the VIES holder name (previously kept).

### Security (review of the whole code base)
- Fixes from an independent four-part review (see SECURITY.md): renewal withdraws an unpaid upgrade, serialized orders for coupon limits, coupons only follow package changes to valid products, negative credit after chargebacks suspends services and blocks orders, disputed/refunded referral payments, login lockout per account and IPv6 network, role managers limited to their own permissions, public cancellation forms without mail bombing, Proxmox/TeamSpeak ownership checks before every action, upgrade chargebacks, tax country locked after the first paid invoice, due dates need `invoices.manage`, hidden status components, login timing, daily 2FA limit, no internal error texts for clients, suspension revokes API tokens, webhook rate limits and PayPal prechecks, credentials re-entry on endpoint change, strict loopback check for relaxed TLS, Proxmox backup storage kept on package changes, Plesk FTP prefix `nxw`, Docker port bound to `127.0.0.1`.

### Changed (admin design)
- The admin area uses the design of the customer portal: sidebar with icons and grouped sections (only those the role may use), customer search in the top bar, shortcuts to the status page and the customer area, avatar with role, dashboard with hero, quick actions and key figure cards; dark/light mode and phone layout.

### Added (status page)
- Public **status page** `/status` and `/status.json`: components with automatic state (incidents, maintenance, failed integration health checks), incidents with impact and timeline, **maintenance announcements** with automatic start/end, history of 14 days; notice on the customer dashboard; optional e-mail notification of affected customers (EN/DE); admin management with drafts, permission `status.manage`, scheduler task `status.maintenance`, migration `2026_11_29_000016`.

### Security (sixth review)
- Fixes from four independent reviews of the latest features (see SECURITY.md): SKRIME ownership by product ID (domain takeover via failed orders), no repeat orders or renewals after lost answers, renewals only for paid periods; Hetzner rescue boot confirmation, host key fingerprints, checked wipe steps, auction price validation, queue fixes, termination of undelivered purchases; Proxmox container time and output limits, SSH environment not passed on, IPv6 socket addresses, overlapping and special IPv6 pools refused; alert mail budgets, password reset lifts the lockout, auxiliary storage errors never abort payments, more location evidence conflicts and a filter for missing evidence.

### Added (global sales, wide package card)
- **Global sales** (Admin → Sale campaigns): percentage for all products, categories or selected products within a period, permanent or for the first billing period; never added to a product promotion (the higher one wins); stored with the order item; site-wide banner with countdown, notice in the shop, sale name and previous price (§ 11 PAngV) on the product pages; migration `2026_11_29_000020_create_sales`.
- The last package of a tab is shown as a wide card (name and price, features in two columns, order button).

### Added (website catalogue: product lines, promotions, slots, domain prices)
- Product menu with groups and sub-menus; product lines as tabs (*Configurator* / *Packages* per line); package cards with *Configuration* and feature sections (`# Heading` lines); configurator layout with sliders and a summary; sold-out products shown with a disabled button and *Available again soon*; advantages per page from the settings.
- **Promotions**: percentage for orders within a period, permanent for the ordered service (renewals, later resource changes); promotion banner, saving and previous price per § 11 PAngV from the new **price history**; admin fields with time zone handling.
- **TeamSpeak slot configurator** (slot steps and step price) and **resource changes of configured services** (servers and TeamSpeak) through the upgrade/downgrade flow: prorated upgrade invoice, downgrade at the end of the period, no stock movement, promotion kept.
- **Domain first-year prices** for registrations and transfers (one-time discount or surcharge, renewals at the product price); the TLD import sets renewal, registration and transfer prices separately; domain page with the six popular endings as cards (registration/renewal price, *Sale*, availability for a searched name), and a paged list with registration, renewal and transfer prices.
- Migration `2026_11_29_000019_add_website_catalog`.

### Added (public website, server configurator, TLD import)
- **Public website** at `/` (can be switched off): start page with original isometric illustration, domain search, product overview, feature highlights, order steps, Trustpilot reviews (two-click TrustBox, CSP extended only on that page) and partner/API section; product pages per category with packages, technical data and prices incl. VAT (§ 19 UStG note for small businesses), domain page with availability check (rate-limited) and all endings, "About us" page; dark design in the brand colour, responsive, no third-party resources by default; settings group *Public website*; `robots.txt` opens the public pages.
- **Server configurator** for KVM and LXC products: customers choose cores, memory and disk within staff-defined ranges; price calculated on the server (order page, order, invoice, renewal), validated range/step, resources passed to the service configuration; configurator tab on the website, ranges and order options in the API.
- **Import TLDs** from the SKRIME price list (Admin → Products): preview, markup in % and fixed, rounding, minimum price, cost limit, TLD filter, optional re-pricing; never deletes; audit-logged. The customer store shows the popular endings and a search when there are many.
- Sign-in keeps the query of the page that required it (e.g. a configuration chosen on the website); order pages take non-secret options from the address (e.g. the domain found by the domain search).

### Added (TeamSpeak over SSH, Redis)
- **TeamSpeak ServerQuery over SSH** (encrypted, TeamSpeak 3.3+/6, port 10022): password login with the ServerQuery credentials, pinned host key (required), selectable per integration next to raw TCP; changing the connection type requires the password again.
- **Redis** as optional store for sessions and rate limits (`SESSION_DRIVER`, `RATE_LIMIT_DRIVER`, `REDIS_URL`, new dependency `predis/predis`): atomic Lua scripts, hashed keys, TTL-based expiry, revoked sessions are never written back; Docker Compose profile `redis` (password, `noeviction`); drivers and Redis status on the system page; CI runs the Redis tests against a Redis service.
- SSH host key comparison accepts RSA keys reported with their signature algorithm (`rsa-sha2-256/512`) – previously RSA-only hosts could never be pinned.

### Added (SKRIME domains)
- **SKRIME** as second domain registrar (sandbox or live, API key encrypted): availability check, registration and transfer with the customer as registrant, auto renewal after activation, name servers, auth code on request, suspension/termination through auto renewal; premium domains refused, `.eu` registrant rule checked before the order (all registrars). Domain products can use INWX or SKRIME.
- Registrar interface: auth codes are fetched only on request; registrars that take the registrant inline receive the contact data.

### Added (security alerts, PayPal tokens, location evidence)
- Security e-mails (EN/DE) when sign-in is locked after failed attempts and when the correct password was entered with a wrong two-factor code; at most one per kind and account every 6 hours.
- PayPal access tokens are cached encrypted until shortly before they expire and renewed once after a 401 (new table `nx_cache`, pruned by `maintenance.cleanup`).
- OSS location evidence: the country of the payment method (Stripe card/bank country, PayPal account country) is stored with paid invoices; contradictions with the billing country are flagged, filterable and marked as checked by staff (migration `2026_11_29_000018`).

### Added (Proxmox: IPv6 pools and LXC root passwords)
- **Static IPv6** from IPv6 pools (single addresses of an on-link prefix, link-local gateways, up to 4096 per pool) for VPS and LXC, alongside IPv4 pools; SLAAC stays the default.
- **LXC root password reset** without reinstalling (optional): SSH to the node with a panel-generated key restricted to the forced command `resources/proxmox/nebrix-lxc-passwd` (ownership marker checked on the node, password on stdin, pinned host keys).
- Integration forms accept multi-line private SSH keys and check their format; customer-facing action messages are translated into German.

### Added (Hetzner: disk wiping and server auction)
- **Automatic disk wiping** of returned Hetzner servers (opt-in per integration): rescue system with a panel-generated Ed25519 key only, pinned rescue host key, wipe script over SSH (*full*: NVMe format / SSD discard / HDD overwrite; *quick*: signatures and disk edges), progress log, 3 boot attempts, return to the pool only after a successful wipe; queue with retry on the integration page, scheduler task `hetzner.wipe`, migration `2026_11_29_000017`, new dependency `phpseclib/phpseclib` (SSH).
- **Server auction** (*market* mode): buys the cheapest auction server within the product's maximum price, minimum memory/disk, CPU text and location; idempotent by order comment, next offer after a cancelled order (at most three), waits while nothing matches.
- Hetzner firewall left by a suspension is cleared before a wipe and when a pool server is assigned (previously a terminated suspended server kept dropping all traffic for its next customer).

### Added (upgrades and downgrades)
- **Package changes** between products of the same upgrade group: upgrades are invoiced prorated for the rest of the paid period and applied through the queue after payment, downgrades take effect at the end of the paid period with the next renewal at the new price; withdraw, expiry of unpaid upgrade invoices, follow-up invoice when a renewal was paid in between, stock reservation, coupon and customer-group discounts kept.
- Remote changes for Proxmox (CPU, memory, swap, network rate, disk growth), Pterodactyl (build limits), TeamSpeak (slots), Nextcloud (quota) and Plesk (service plan switch).
- Customer page *Upgrade or downgrade*, staff change (also without invoice) with history and retry, REST API `GET/POST /services/{id}/package`, e-mail "package changed" (EN/DE), scheduler task `services.package_changes`, migration `2026_11_29_000015`.

### Added (coupons and refer a friend)
- **Coupon codes** (Sales → Coupons): percent or fixed discounts on the first invoice or permanently on renewals, validity dates, product restriction, total and per-customer limits, new-customers-only option; price preview on the order page (rate-limited), `coupon` field in `POST /api/v1/orders`, release of the use when an unpaid order is cancelled, list of uses per coupon.
- **Refer a friend**: personal referral link and code per customer, code field on the registration form, reward as account credit (optional welcome credit for the new customer) after a payment with real money of at least the minimum amount and a holding period; refunds reset the referral, monthly limit per referrer, sign-ups from the referrer's own IP address are held for staff review (Customers → Referrals); e-mails in English and German; hourly scheduler task `referrals.process`.
- Migration `2026_11_29_000014` (additive).

### Fixed (system test of the Docker installation)
- Docker build: the composer stage failed on the missing `pdo_mysql` extension of the composer image; it is now ignored there (the runtime image installs it).
- New `.dockerignore`: `.env`, `.git`, local `vendor/`, tests, logs and runtime data are no longer copied into the image (previously a `.env` next to the Dockerfile would have ended up in the image).
- docker-compose: worker and scheduler no longer report "unhealthy" (the image's Apache health check does not apply to them); the console service checks its own port.
- German interface completed (over 1,000 texts: customer area, administration, settings, product fields, permissions, help texts) with a test that keeps every interface text translated; visitors get their browser language; tax rates and discounts are formatted per language ("19 %", "7,5 %"); amounts no longer wrap in tables.
- Order form: the tax line shows the rate that is actually charged (0 % for reverse charge) instead of the product's rate.
- Invoice lines, renewal descriptions, refund lines and e-mail dates are written in the customer's language and date format.

### Security (billing, contracts and backups)
- Fourth independent review; all findings fixed with regression tests: rejected or replaced cancellations no longer terminate the service (the end date is removed, only scheduled declarations are executed), customers can only take back their own automatic cancellations, staff cannot set past end dates, already cancelled services are not offered again; open invoices of cancelled or ended services are not paid automatically from credit or reminded; reverse charge requires a company name matching the VIES holder name, and a VIES outage keeps an earlier confirmation; per-network and per-recipient limits on the public cancellation forms; distinct number prefix for correction documents; backup listing failures no longer hide power actions; voided unpaid invoices no longer promise a refund; invoice documents are generated after the transaction by the mail worker; existing customers keep manual payment until they opt in.

### Security
- Removed committed secrets from the working tree (they remain in the history and must be rotated by the owner), insecure Docker defaults and phpMyAdmin.
- Fixed the legacy vulnerabilities documented in AUDIT.md (session token design, impersonation, SSH command execution, unverified PayPal IPN, double-spend race, CSRF, XSS, IDOR, open redirects, SSRF, unprotected cron endpoints, plaintext third-party credentials).
- Independent security review of the new code; all reported findings were fixed: TeamSpeak port reservation and safe adoption, revoked sessions cannot be resurrected, Proxmox guest ownership marker, staff role-escalation guard, atomic rate limiting, refund/chargeback reversal, refund idempotency keys, atomic TOTP/recovery code use, password confirmation for enabling 2FA, IPv4-embedding IPv6 ranges blocked, free renewal settlement, raw PayPal webhook verification.
- Second independent review of the infrastructure features; all findings fixed: domains cannot be taken over by another customer (registrant ownership, duplicate checks) and transfers activate only after completion (with a deadline); one Plesk subscription per domain; Hetzner ownership checked live before every action, rescue system deactivated before installations, full firewall backup, safe pool prefixes, test-mode orders replaced by real orders; console proxy with backpressure, per-IP/per-user/handshake limits, loopback default and memory limit; stricter legacy ownership (Proxmox host name, Pterodactyl identifier); atomic reinstall, provisioning retry after completed jobs, cloud-init fallback only with a cloud-init drive, IPv6 `Host` header for console upstreams.

### Removed
- Legacy RED-Host code paths: Venocix, Plesk and paysafecard/Boltlayer integrations, the PayPal IPN handler, HTTP cron endpoints, support-PIN login, the Discord bot API.
- Archived/unused dependencies (`paypal/rest-api-sdk-php`, duplicate Proxmox and TeamSpeak libraries).

### Known limitations
- Integrations and payment providers were tested against stateful fakes only; live/sandbox verification is pending. This includes Proxmox/Pterodactyl backups and the EU VIES service.
- XRechnung files are validated against the CII XSD in the tests; the official EN 16931/XRechnung Schematron rules (KoSIT validator) have not been run yet.
- Built-in EU VAT rates reflect the state of November 2026 and must be kept current (`tax.rate_overrides`). Tax and legal texts are technical defaults, not legal advice.
- SKRIME was tested against a fake of its documented API and the public live endpoints only; no real order was placed.
- The LXC password forced command was tested with a simulated `pct` and a local SSH server, not on a Proxmox node.
- Hetzner: automatic disk wiping and server auction orders were tested against a simulated Robot API and a local SSH server, not on Hetzner hardware.
