# Changes: marketing (SEO, landing pages, blog, review invitations, newsletter)

Details: [DOCUMENTATION/MARKETING.md](DOCUMENTATION/MARKETING.md).

## User-visible changes

- **SEO**: generated `/robots.txt` (private areas disallowed, sitemap linked) and `/sitemap.xml` (public content only, `lastmod`, only while the website is on); canonical address, meta description, OpenGraph and Twitter card tags on every website page; preview image for social networks (upload in Settings → Marketing, PNG/JPEG by content); schema.org JSON-LD: Organization/WebSite (start page), BreadcrumbList, Product + Offer with the shown gross price, currency and InStock/OutOfStock (product and landing pages), FAQPage, BlogPosting. CSP unchanged (JSON-LD is a data block, encoded with JSON_HEX_*).
- **Landing pages** at `/{slug}` (e.g. `/minecraft-server-mieten`): admin CRUD with draft/published, staff preview, SEO title/description, hero, Markdown introduction, advantages, FAQ and the packages/configurators of a product page (optionally one product line); addresses checked against all routes and a reserved list.
- **Blog**: admin CRUD (Markdown, cover image, author, publication date – future = scheduled), `/blog` with pagination, `/blog/{slug}`, Atom feed `/blog/feed.xml`, latest posts on the start page, navigation/footer link.
- **Review invitations** (off by default): one Trustpilot invitation per customer N days after the first service became active, only with explicit consent (unticked checkbox at registration and order, toggle in Account → E-mail preferences), opt-out link and one-click headers.
- **Newsletter** (off by default): `/newsletter` sign-up with double opt-in (CSRF, CAPTCHA if configured, rate limits, same answer for every address), confirmation page, one-click unsubscribe (RFC 8058), subscription in the customer account, admin subscriber list (search, CSV export, deletion), campaigns (Markdown, preview, test to own address, throttled batches through the job queue, per-recipient idempotency, counts).
- New customer page **Account → E-mail preferences** (`/account/communication`); new admin sections **Marketing** (Landing pages, Blog, Newsletter) and **Configuration → Marketing**.

## Migrations

- `2026_11_30_000030_create_marketing_content`: `nx_landing_pages`, `nx_blog_posts`; grants `content.view`, `content.manage` to the role `admin`.
- `2026_11_30_000031_create_review_invitations`: `nx_marketing_consents` (user, purpose, granted_at/source/ip/text, withdrawn_at/via), `nx_review_invitations` (one per user, opt-out token hash).
- `2026_11_30_000032_create_newsletter`: `nx_newsletter_subscribers`, `nx_newsletter_campaigns`, `nx_newsletter_deliveries`; grants `newsletter.view`, `newsletter.manage`, `newsletter.export` to the role `admin`.

All additive; no existing table or column changed.

## Settings (`config/settings/marketing.php`, page `/admin/settings/marketing`, permission `settings.branding`)

`seo.og_image` (hidden, set by the upload), `seo.home_description`, `seo.same_as`; `blog.enabled` (off), `blog.per_page` (10), `blog.home_posts` (3); `newsletter.enabled` (off), `newsletter.confirm_hours` (48), `newsletter.batch_size` (50), `newsletter.batch_interval` (60 s); `reviews.enabled` (off), `reviews.delay_days` (14), `reviews.review_url` (Trustpilot https address).

## Permissions (group "Marketing")

`content.view`, `content.manage`, `newsletter.view`, `newsletter.manage`, `newsletter.export`.

## Routes (`config/routes/marketing.php`)

Public: `GET /sitemap.xml`, `GET /robots.txt`, `GET /media/{name}`, `GET /blog`, `GET /blog/feed.xml`, `GET /blog/{slug}`, `GET|POST /newsletter`, `GET /newsletter/confirm/{token}`, `POST /newsletter/confirm`, `GET|POST /newsletter/unsubscribe/{token}` (POST without CSRF: RFC 8058 one-click, token-authenticated), `GET|POST /reviews/opt-out/{token}` (same), fallback `GET /{slug}` (landing pages). Customer: `GET|POST /account/communication`. Admin: `/admin/seo/og-image[/remove]`, `/admin/landing-pages…`, `/admin/blog…`, `/admin/newsletter…` (see the route file).

## Scheduler tasks and jobs

- `reviews.invitations` (60 min), `newsletter.prune_unconfirmed` (daily).
- Job type `newsletter.batch` (`Nebrix\Marketing\NewsletterBatchJob`, registered in `JobRegistry`).

## Mail templates (de/en)

`review_invitation`, `newsletter_confirm`, `newsletter` (frame of every campaign).

## Shared files touched (small, additive)

- `src/Http/Router.php`: route option `fallback` (matched after all other routes, never turns an unknown address into a 405), `isFallback()`.
- `src/Mail/Mailer.php`: optional named arguments `headers` (whitelisted List-Unsubscribe headers, added on delivery), `html` (trusted HTML placeholders) and `uniqueKey` for `queue()`; `src/Mail/MailTemplates.php`: `render()` takes the trusted HTML placeholders.
- `src/Controller/WebsiteController.php`: dependency `SiteContext`; adds the SEO data to every page and the latest posts to the start page.
- `src/Controller/Admin/SettingsController.php`: settings page `marketing`.
- `src/Controller/Auth/RegisterController.php`, `src/Controller/Customer/StoreController.php`: record the optional review consent (one line each).
- `src/Customer/CustomerService.php`: account closure deletes marketing consents and the newsletter subscription.
- `src/Scheduler/Scheduler.php` (two tasks), `src/Provisioning/JobRegistry.php` (one job), `src/Authorization/Permission.php` (group "Marketing").
- Templates: `layouts/website.twig` (robots block, description default, SEO head include, `marketing.css`, Blog/Newsletter links, newsletter strip), `layouts/admin.twig` and `layouts/customer.twig` (navigation), `website/home.twig` (latest posts), `auth/register.twig` and `customer/store/order.twig` (consent checkbox include), `admin/settings.twig` (preview image card on the marketing page).
- `config/mail_templates.php`, `config/mail_templates_de.php` (three templates appended).
- **Removed `public/robots.txt`** – the web server would serve it instead of the generated `/robots.txt`. Installations that customised it must move their rules (the generated file keeps the previous allow-list approach).

## Tested only against fakes

- SMTP delivery of the unsubscribe headers: local fake SMTP server (`tests/Support/smtp_capture_server.php`).
- CAPTCHA verification: mocked HTTP (`FakeHttp`).
- Trustpilot: only linked, no API calls. Real mail providers' one-click handling was not tested.

## Limitations

- Uploaded images are stored as uploaded (no re-encoding, EXIF metadata kept) in `storage/uploads/marketing` (needs backups).
- Landing pages and posts are single-language.
- No open/click tracking in campaigns (on purpose).
- A staff change of a customer's e-mail address does not update an existing newsletter subscription.
- The review consent checkbox on the order page is shown even if the customer already agreed (ticking it again changes nothing; leaving it unticked does not withdraw).
