# Nebrix Panel

Nebrix is a self-hosted hosting management platform: customers order and manage servers, game servers, voice servers and cloud storage; staff manage customers, products, billing, payments, support and the connected infrastructure.

It is the modernized successor of the open-source **RED-Host Panel** by Björn Schleyer, rewritten for PHP 8.3/8.4 with a security-first architecture.

## Features

* **Public website** – start page, product menu with sub-menus, product pages with product lines as tabs, packages (sold-out ones marked) and configurators (KVM/LXC: cores, memory, disk; TeamSpeak: slots), promotions with previous price (§ 11 PAngV), domain search over the popular endings with registration/renewal/transfer prices, Trustpilot reviews (two-click), "About us"; prices incl. VAT, dark design in the brand colour; can be switched off. Configured services can change their resources later (prorated).
* **Customer panel** – store and ordering, services with live status and power actions, invoices and online payment, account credit, support tickets, profile, two-factor authentication and session management (English/German).
* **Admin panel** – dashboard, customers and groups, products, orders, invoices, transactions and refunds, services, tickets, integrations, e-mail templates, branding, settings, staff with role-based permissions, audit log, background jobs.
* **Automated provisioning** – Proxmox VE (KVM and LXC, API tokens, multiple clusters, IPv4/IPv6 pools, reinstall, password reset incl. LXC, noVNC console), Hetzner Robot (dedicated servers from a pool, ordered or bought from the server auction, automatic disk wiping), Plesk (webhosting), INWX and SKRIME (domain registrations and transfers; all SKRIME endings importable with markup), Pterodactyl (Minecraft and other game servers), Nextcloud (storage accounts), TeamSpeak 3 (virtual servers, ServerQuery over SSH or raw TCP). Products without an integration are fulfilled manually.
* **Payments** – Stripe Checkout and PayPal (Orders v2) with verified webhooks, account credit, manual payments, refunds and dispute handling.
* **Billing automation** – recurring renewals, automatic payment from credit, payment reminders, overdue suspension, optional termination, idempotent job queue with retries.
* **Invoices and taxes (EU)** – PDF invoices and XRechnung e-invoices with an unchangeable archive, cancellation/correction documents, reverse charge with VIES-verified VAT IDs, OSS rates, small-business switch (§ 19 UStG).
* **Contracts** – cancellation button (§ 312k BGB) and withdrawal function with immediate receipt e-mails, automatic end of cancelled services; e-mails in the customer's language.
* **Customer backups** – Proxmox (vzdump, restore, delete) and Pterodactyl (create, restore, download, delete) with per-product limits.
* **Customer portal & Discord** – dashboard with services by product group, product search, support PIN and monthly costs; Discord account link with server join and automatic customer roles.
* **REST API** – token-authenticated JSON API (`/api/v1`, OpenAPI 3.1) for customers (orders, services and actions, invoices and payment, tickets) and staff (customers, services, orders, invoices, tickets) with scoped, expiring tokens, rate limits and idempotency keys.

## Security highlights

Argon2id passwords, TOTP 2FA, database sessions with hashed IDs, CSRF protection, strict Content Security Policy, auto-escaped templates, RBAC with server-side checks, encrypted secrets (libsodium), SSRF-protected integrations, signature-verified payment webhooks, idempotent payment processing and an append-only audit trail. Details: [SECURITY.md](DOCUMENTATION/SECURITY.md).

## Requirements

PHP 8.3 or 8.4 (`pdo_mysql`, `sodium`, `curl`, `mbstring`, `openssl`, `fileinfo`), MariaDB 10.6+ or MySQL 8.0+, Composer 2, a web server with the document root on `public/`, and TLS in production. Docker images are provided.

## Quick start (Docker)

```bash
cp .env.example .env
```

```bash
docker compose build && docker compose run --rm --no-deps app php bin/nebrix key:generate
```

Put the printed key into `APP_KEY` in `.env`, set the database credentials, then:

```bash
docker compose up -d && docker compose exec app php bin/nebrix migrate
```

```bash
docker compose exec app php bin/nebrix user:create-admin --email=admin@example.com
```

Full instructions, including a manual installation: [INSTALLATION.md](DOCUMENTATION/INSTALLATION.md).

## Documentation

| Document | Content |
|---|---|
| [INSTALLATION.md](DOCUMENTATION/INSTALLATION.md) | Requirements, Docker and manual installation, worker and scheduler |
| [CONFIGURATION.md](DOCUMENTATION/CONFIGURATION.md) | Environment variables, admin settings, scheduler tasks |
| [ADMIN_GUIDE.md](DOCUMENTATION/ADMIN_GUIDE.md) | Using the administration area |
| [INTEGRATIONS.md](DOCUMENTATION/INTEGRATIONS.md) | Proxmox (IP pools, console), Hetzner Robot, Plesk, INWX, SKRIME, Pterodactyl, Nextcloud, TeamSpeak |
| [PAYMENTS.md](DOCUMENTATION/PAYMENTS.md) | Stripe, PayPal, credit, refunds, disputes |
| [SECURITY.md](DOCUMENTATION/SECURITY.md) | Protections and remaining risks |
| [ARCHITECTURE.md](DOCUMENTATION/ARCHITECTURE.md) | Code structure and main flows |
| [DATABASE.md](DOCUMENTATION/DATABASE.md) | Schema, migrations, backups |
| [API.md](DOCUMENTATION/API.md) | REST API (tokens, scopes, endpoints), webhooks, health check, CLI, extension points |
| [UPGRADE_GUIDE.md](DOCUMENTATION/UPGRADE_GUIDE.md) | Migration from RED-Host and updates |
| [AUDIT.md](DOCUMENTATION/AUDIT.md) | Security audit of the legacy code base |
| [IMPLEMENTATION_ROADMAP.md](DOCUMENTATION/IMPLEMENTATION_ROADMAP.md) | Modernization progress and open items |
| [CHANGELOG.md](DOCUMENTATION/CHANGELOG.md) | Changes per version |

## Status

Version 2.0.0 is in development. All integrations (including backups and the EU VIES check) and payment providers are covered by automated tests against mocked APIs but have not yet been verified against live systems – test them in a staging environment before production use (see the "Test status" notes in INTEGRATIONS.md and PAYMENTS.md).

## Development

```bash
composer install
```

```bash
composer lint && composer analyse && composer test
```

Integration and feature tests need a MariaDB/MySQL test database configured via `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_DATABASE`, `TEST_DB_USERNAME` and `TEST_DB_PASSWORD`; the test suite creates and resets the `nx_` tables in that database. **Never point it at a production database.**

## Contributing

Contributions are welcome. Please open an issue or pull request; report security issues privately to the repository owner (see SECURITY.md).

## License

Nebrix is open-source software licensed under the [MIT license](LICENSE). Based on the RED-Host Panel, © 2022 Björn Schleyer.
