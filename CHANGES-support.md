# Changes: support area

Details: [DOCUMENTATION/SUPPORT.md](DOCUMENTATION/SUPPORT.md).

## User-visible changes

* **Help center** (`/help`, website layout): categories, articles in safe Markdown, search over title and body, "Was this helpful?" (one answer per visitor and article, rate-limited). Linked from the customer area, the ticket pages and the website footer. Admin → Support → Help center to manage categories and articles (published/draft, sort order, preview, helpful counters).
* **Article suggestions** while customers type the subject of a new ticket (JSON endpoint + `support.js`).
* **AI assistant** (optional, **off by default**): `/help/assistant` for signed-in customers answers from the published help articles via the Claude API; every answer is marked as AI-generated and offers "Create ticket" prefilled with the question. Strict limits (question length, tokens, per customer per hour/day, daily cap); no customer data is sent.
* **Ticket attachments** for customers and staff (images, PDF, text/log; checked by content; size/count limits; stored under `storage/attachments`; download only for the owner and staff with `tickets.view`).
* **Canned responses** (Admin → Support → Canned responses) with placeholders `{customer_name}`, `{ticket_id}`, `{ticket_subject}`, `{staff_name}`, inserted into the admin reply box.
* **E-mail replies**: ticket notifications get a signed personal Reply-To address; replies posted by the mail provider to `/webhooks/inbound-mail` (HMAC signature + timestamp) become ticket replies (sender check, quote/signature stripping, auto-reply/bounce filter, idempotent by Message-ID, size and rate limits).
* **Assignment e-mail** to the staff member a ticket is assigned to.
* **Satisfaction rating** (1–5 + comment) of closed tickets by their owner, once; rating link in the new closing e-mail; ratings and averages in the admin ticket list and ticket page.
* **Account → Notifications**: customers choose which optional e-mails they receive (payment received, service ready, service suspended, ticket replies, maintenance/incidents); invoices and security/account/contract e-mails are always sent and the page says so. Optional **Discord direct messages** for customers with a linked Discord account.
* **Staff notifications in a Discord channel** (webhook): new orders, new tickets, failed payments, failed provisioning – numbers and links only.
* **Status page subscriptions** by e-mail with double opt-in and one-click unsubscribe (`List-Unsubscribe`, RFC 8058).

## Migrations, tables and columns

| Migration | Content |
|---|---|
| `2026_11_30_000040_create_help_center` | `nx_help_categories` (slug unique, title, description, sort order, published), `nx_help_articles` (category, slug unique per category, title, Markdown body, sort order, published, `helpful_yes`/`helpful_no`, created/updated by, `published_at`); grants `help_center.manage` to `admin` and `support` |
| `2026_11_30_000041_extend_support_tickets` | `nx_ticket_messages.source` (`web`, `email`, `api`; default `web`); `nx_ticket_attachments` (ticket, message, uploader, staff flag, display name, random stored name (unique), detected MIME type, size, SHA-256); `nx_canned_responses`; `nx_ticket_ratings` (one per ticket: rating 1–5, comment, owner); `nx_ticket_inbound_mails` (SHA-256 of the Message-ID unique, ticket, created message, status/reason); grants `canned_responses.manage` to `admin` and `support` |
| `2026_11_30_000042_create_notification_tables` | `nx_notification_preferences` (user, event, `email`, `discord`); `nx_staff_notifications` (posted staff events: unique event key, kind, reference ID – deduplication and cursor); `nx_status_subscribers` (e-mail unique, locale, SHA-256 of the confirmation token, its expiry, confirmation time, random salt of the unsubscribe token) |

All additive (`CREATE TABLE IF NOT EXISTS`, column existence check).

## Settings (`config/settings/support.php`)

Shown in Admin → Settings → System (groups added to `SettingsController::PAGES`):

* `support` – *Support & help center*: `support.help_center_enabled` (on), `support.ticket_suggestions` (on), `support.attachments_enabled` (on), `support.attachment_max_mb` (5), `support.attachment_max_files` (3), `support.ratings_enabled` (on), `support.email_replies_enabled` (off), `support.reply_local_part` (`support`), `support.reply_domain`, `support.inbound_secret` (secret, ≥ 32 characters).
* `assistant` – *AI assistant*: `assistant.enabled` (**off**), `assistant.api_key` (secret), `assistant.model` (model ID, default: the current recommended model), `assistant.effort` (`low`), `assistant.max_tokens` (2000), `assistant.max_question_chars` (500), `assistant.hourly_limit` (10), `assistant.daily_limit` (30), `assistant.global_daily_limit` (500).
* `notifications` – *Notifications*: `notifications.discord_dm` (off), `notifications.staff_webhook_url` (secret, must match `https://discord.com/api/webhooks/<id>/<token>`), `notifications.staff_events` (all four), `notifications.status_subscriptions` (on).

## Permissions

* `help_center.manage` – Manage help center categories and articles.
* `canned_responses.manage` – Manage canned responses for ticket replies.
* Both added to the *Support* group of `Permission::grouped()` and to the default `support` role; existing installations get them through the migrations (admin and support roles).

## Routes (`config/routes/support.php`)

Public: `GET /help`, `GET /help/search`, `GET /help/suggest` (JSON), `POST /help/articles/{id}/vote`, `GET /help/{category}`, `GET /help/{category}/{article}`; `POST /webhooks/inbound-mail` (CSRF off, HMAC); `GET|POST /status/subscribe`, `GET /status/subscribe/confirm/{token}`, `POST /status/subscribe/confirm`, `GET /status/unsubscribe/{id}/{token}`, `POST /status/unsubscribe/{id}/{token}` (CSRF off, signed token, RFC 8058).

Customer (`auth`, `verified`): `GET|POST /help/assistant`, `POST /help/assistant/clear`, `GET /tickets/{id}/attachments/{attachment}`, `POST /tickets/{id}/rating`, `GET|POST /account/notifications`.

Admin (`auth`, `staff`): `GET /admin/tickets/{id}/attachments/{attachment}` (`tickets.view`); `/admin/help…` (`help_center.manage`: index, categories create/store/edit/update/delete, articles create/store/edit/update/delete); `/admin/canned-responses…` (`canned_responses.manage`: index, create, store, edit, update, delete).

Changed existing routes (same URLs): customer ticket create/store/show/reply (attachments, prefill, rating), admin ticket index/show/reply/update (attachments, canned responses, ratings, assignment and closing e-mails).

## Scheduler tasks and jobs

* Task `notifications.staff_discord` (every minute): queue staff channel posts.
* Task `status.subscriptions_cleanup` (hourly): delete expired unconfirmed subscriptions.
* Jobs: `notifications.discord_dm` (`DiscordDirectMessageJob`), `notifications.staff_discord` (`StaffWebhookJob`), registered in `JobRegistry`.

## Mail templates (English and German)

* `ticket_closed` – Support ticket closed (with rating link).
* `ticket_assigned` – Support ticket assigned (staff).
* `status_subscription_confirm` – Status page: confirm subscription.
* `status_subscriber_update` – Status page: update for subscribers (with unsubscribe link).
* `ticket_reply` now carries the personal Reply-To address when e-mail replies are enabled.

## Shared files touched

* `src/Mail/Mailer.php`: `queue()` takes optional headers (`reply_to`, `list_unsubscribe` → `List-Unsubscribe` + `List-Unsubscribe-Post`), validated; applies the customer's notification preferences through the optional `NotificationRouter` dependency.
* `src/Status/StatusService.php`: optional `StatusSubscriptions` dependency; confirmed subscribers are e-mailed for published new entries and staff updates.
* `src/Support/TicketRepository.php`: `addMessage()` returns the message ID and takes a source; `firstMessageId()`; rating in the admin list.
* `src/Integration/Discord/DiscordClient.php`: `sendDirectMessage()`.
* `src/Controller/Admin/TicketController.php`, `src/Controller/Customer/TicketController.php`, `src/Controller/Api/Admin/TicketController.php` (staff replies notify through `TicketNotifier`, source `api`), `src/Controller/Api/TicketController.php` (source `api`).
* `src/Controller/Admin/SettingsController.php` (three groups on the System page), `src/Authorization/Permission.php`, `src/Provisioning/JobRegistry.php`, `src/Scheduler/Scheduler.php` (two dependencies, two tasks).
* `config/mail_templates.php`, `config/mail_templates_de.php` (four templates after `ticket_reply`).
* Templates: `layouts/customer.twig` (Help center, Notifications), `layouts/admin.twig` (Canned responses, Help center), `layouts/website.twig` (footer link), `status/index.twig` (subscribe link), customer/admin ticket templates.
* `.gitignore`: `/storage/attachments/`.
* New assets: `public/assets/css/support.css`, `public/assets/js/support.js` (included by the support templates).

## Tested against fakes only

* Claude API (AI assistant): fake HTTP client; no real request was made.
* Discord direct messages and the staff channel webhook: fake Discord API and fake DNS resolver.
* Inbound e-mail: simulated signed webhook requests (no real mail provider).
* List-Unsubscribe headers: checked in the queued message (SMTP disabled in tests).

## Limitations / open points

* In Docker only `storage/invoices` is a volume: add a volume for `storage/attachments` (e.g. `attachments:/var/www/nebrix/storage/attachments` for web and worker) so attachments survive rebuilds.
* Attachments of inbound e-mails are not imported.
* Help center search is `LIKE`-based (no stemming/typo tolerance).
* Staff channel posts are delayed by up to one minute (scheduler).
* Pre-existing, not changed: `GET /admin/settings/system` commits the test transaction (the pending-migrations check runs `CREATE TABLE IF NOT EXISTS nx_migrations`, an implicit commit in MariaDB), so data of a test that opens it leaks into later tests of the same run. The support tests avoid opening it.
