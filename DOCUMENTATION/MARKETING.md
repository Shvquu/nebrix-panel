# Marketing

SEO basics of the public website, landing pages, a blog with Atom feed, review invitations with consent and a newsletter with double opt-in. Everything is switched off or invisible until an administrator enables it; Germany is the main market, so UWG, GDPR (DSGVO) and TDDDG/DDG rules shaped the design.

Settings: **Admin → Configuration → Marketing** (`/admin/settings/marketing`, permission `settings.branding`). Content: **Admin → Marketing → Landing pages / Blog / Newsletter**.

## SEO

| Address | Content |
|---|---|
| `/robots.txt` | Generated. Allows the start page, `/products`, `/about`, `/status`, `/media/`, the blog and `/newsletter` (when switched on) and every published landing page; disallows the administration, customer area, account, API, store, sign-in and the e-mail link pages; ends with `Disallow: /` and `Sitemap: <APP_URL>/sitemap.xml`. With the website switched off only `/status` is allowed and there is no sitemap line. The former static `public/robots.txt` was removed – a static file would be served by the web server instead of the generated one. |
| `/sitemap.xml` | Only while the website is switched on (otherwise 404). Start page, `/products`, every product page with public products (incl. the domain page), `/about` (if it has a text), published landing pages, `/blog` and every public post (blog switched on). `lastmod`: newest change of the public products of the page, of the about text, the landing page or the post. Drafts, scheduled posts, hidden/archived/group-only products never appear. |
| `/media/{name}` | Uploaded images (blog covers, the preview image). Public only while they belong to published content; covers of drafts and scheduled posts only for staff with `content.view`. Served with the detected type, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`. |

Every page of the website layout has:

* **Canonical address** (`<link rel="canonical">`, `og:url`): `APP_URL` + path, without query parameters (blog pages keep `?page=N`). Staff previews have none and are `noindex, nofollow`.
* **Meta description**: product pages their teaser, landing pages their meta description (or hero text), posts their teaser (or the beginning of the text), the start page the setting *Meta description of the start page* (or the default text).
* **OpenGraph and Twitter cards**: title, description, site name, locale, type (`article` with publication and change time for posts) and image: the cover of a post, otherwise the **preview image for social networks** uploaded in Settings → Marketing (PNG/JPEG checked by content, max. 2 MB, 4096 px; without an image no `og:image` is sent and the small Twitter card is used).
* **Structured data** (schema.org JSON-LD): `Organization` and `WebSite` on the start page (name, logo, contact data, VAT ID, `sameAs` from the setting *Profiles of your company*), `BreadcrumbList` on every sub-page, `Product` with `Offer` for every package and configurator shown on a product or landing page (price **exactly as shown** – gross incl. VAT, the promotional price during a promotion, the registration price on the domain page –, currency, `InStock`/`OutOfStock`, `priceValidUntil` while a promotion runs, billing period as `UnitPriceSpecification`; for small businesses without the VAT flag), `FAQPage` on landing pages with FAQ entries, `BlogPosting` on posts. At most 30 products per page.

**JSON-LD and the CSP.** The data is printed in `<script type="application/ld+json">`. Browsers treat scripts of a non-JavaScript type as data blocks and never execute them; the CSP check (`script-src 'self'`) only applies to scripts that would run, so the policy stays unchanged – no `unsafe-inline`, nonce or hash is needed. The JSON is encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` (`Nebrix\Marketing\JsonLd`), so no text from products, settings or FAQ can close the element or open a comment; it is passed to Twig as a `Markup` object built by code – templates never use `|raw`.

## Landing pages

Admin → Marketing → Landing pages (`/admin/landing-pages`). Fields: address (`/minecraft-server-mieten`; lowercase letters, numbers, single dashes), status (draft/published), title and meta description for search engines, headline (`*word*` highlighted) and text below it, introduction (Markdown), advantages (one per line `Title | Text`), FAQ (blocks separated by a blank line: first line question, then the answer), the product page whose packages are shown (`WebsiteCatalog::PAGES`) and optionally one product line (`website_line`). The packages use the partials of the product pages (`_plan`, `_plan_wide`, `_configurator`), including tabs, promotions and sold-out notices.

* Public at `/{slug}` only while published and while the website is switched on; drafts are visible in the staff **preview** (`/admin/landing-pages/{id}/preview`, `noindex`).
* The route is a **fallback route** (`Router` option `fallback`): it is only tried when no other route matches, whatever order the route files are loaded in. An address is refused when it is reserved (`LandingPages::RESERVED`: application areas, `assets`, `uploads`, `media`, `sitemap` …) or when any existing route answers `GET /{slug}`. If a later update adds a route with the same address, the route wins and the list marks the page *address in use*. `/api` is never a landing page (unknown API addresses keep their JSON 404).

## Blog

Switch on in Settings → Marketing (*Show the blog on the website*). Admin → Marketing → Blog (`/admin/blog`): title, address, teaser, text (Markdown), optional cover image (PNG/JPEG by content, max. 2 MB), author, status and publication date. A published post with a date in the future is **scheduled**: it becomes public automatically at that time (no job needed). Public: `/blog` (paginated, *Posts per page*), `/blog/{slug}`, Atom feed `/blog/feed.xml` (20 newest posts, absolute links, the rendered HTML as escaped text), the latest posts on the start page (*Latest posts on the start page*, 0 = none) and a *Blog* link in the navigation and footer. Staff preview: `/admin/blog/{id}/preview`.

## Markdown

`Nebrix\Marketing\Markdown` renders a safe subset for landing pages, posts and newsletters: headings (`#`/`##` → h2, `###` → h3, `####` → h4), paragraphs, lists, quotes, code, bold/italic, rules and links. All input is HTML-escaped first, so raw HTML is shown as text; links are only kept for `http(s)`, `mailto`, site-relative paths (`/products`) and anchors – `javascript:`, `data:`, `//host`, backslashes and quotes are shown as text. Links to other hosts get `rel="nofollow noopener"`. Images are not supported.

## Review invitations

A review request by e-mail is advertising (§ 7 Abs. 2 Nr. 2 UWG) and needs the customer's prior, explicit consent.

* **Consent**: an optional checkbox – never ticked in advance – at registration and on the order page (shown while invitations are switched on), and a toggle in **Account → E-mail preferences** (`/account/communication`). Stored in `nx_marketing_consents` with time, source (`registration`, `checkout`, `account`), shortened IP address (IPv4 /24, IPv6 /48) and the exact text agreed to; withdrawable at any time (account, link in the e-mail); every change is audit-logged (`consent.granted` / `consent.withdrawn`).
* **Sending**: scheduler task `reviews.invitations` (hourly, at most 200 per run). One e-mail (`review_invitation`, de/en, in the customer's language) per customer – ever (unique key in `nx_review_invitations`) – *Send the invitation … days* after the first service became active (`provisioned_at`), only while the customer is active, still has an active service and the consent is active; never to staff. The e-mail contains the review link from the settings (Trustpilot address, https) and an opt-out link `/reviews/opt-out/{token}` (256-bit token, stored as SHA-256), and the headers `List-Unsubscribe` / `List-Unsubscribe-Post: List-Unsubscribe=One-Click`.
* Off by default (*Invite customers to write a review*); without a review link nothing is sent.

## Newsletter

Switch on in Settings → Marketing (*Offer the newsletter*).

* **Sign-up** at `/newsletter` (the footer links there; ordinary pages carry no form, so they need no session): CSRF, the optional CAPTCHA of the installation (hCaptcha/Turnstile, CSP extended on that page only), at most 10 sign-ups per client network (IPv4 address or IPv6 /64) and hour, at most 3 confirmation e-mails per address and day. The answer is **the same for every address** (known, unknown, limited); confirmed addresses get no e-mail.
* **Double opt-in**: the confirmation e-mail (`newsletter_confirm`) contains `/newsletter/confirm/{token}` (256-bit random, stored as SHA-256, valid for *Confirmation link valid for … hours*, single use). The link opens a page with a button, so link scanners of mail providers confirm nothing. The confirmation stores time and shortened IP address; the sign-up stores time, shortened IP address and the text agreed to. Unconfirmed sign-ups are deleted 7 days after the link expired (scheduler task `newsletter.prune_unconfirmed`, daily).
* **Customer account**: customers subscribe/unsubscribe in Account → E-mail preferences. A signed-in customer with a verified e-mail address is confirmed at once (the verified account is the confirmation; time, shortened IP and source `account` are stored); without a verified address the confirmation e-mail is sent instead.
* **Unsubscribing** always works, also when the newsletter or the website is switched off: every newsletter contains `/newsletter/unsubscribe/{token}` (a token per e-mail, stored as SHA-256) in the text and in `List-Unsubscribe`, plus `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058). The GET opens a page with a button; the POST accepts the one-click request of mail clients without CSRF token (the token in the address authenticates it). Unsubscribed addresses are kept with the time of the withdrawal as proof; staff delete them on request.
* **Administration** (Admin → Marketing → Newsletter): subscriber list with search and status filter (`newsletter.view`), CSV export with the proof of consent (`newsletter.export`, spreadsheet formulas neutralised, audit-logged), deletion on request (`newsletter.manage`, the address is not written to the audit log).
* **Campaigns** (`newsletter.manage`): subject, text in Markdown, audience (all confirmed subscribers or one language), preview of the rendered text, test e-mail to the staff member's own address (max. 10 per hour), sending after typing `SEND`. Sending creates one delivery per confirmed subscriber (unique per campaign and subscriber) and the job `newsletter.batch`, which queues *E-mails per batch* e-mails under a row lock of the campaign and schedules the next batch *Seconds between two batches* later. Every e-mail is a `mail.send` job with the key `newsletter-mail:{campaign}:{subscriber}` – a retried or duplicated batch never queues a second e-mail. Recipients who unsubscribed in the meantime are skipped. The overview shows recipients, queued, delivered and failed e-mails (from the job queue). A running campaign can be stopped; only drafts can be changed or deleted (sent e-mails keep working unsubscribe links). Every newsletter uses the mail template `newsletter` (de/en frame with the unsubscribe link).
* Account closure deletes the subscription (by account and address) and all marketing consents.

## Mailer extension

`Mailer::queue()` takes optional named arguments: `headers` (only `List-Unsubscribe` and `List-Unsubscribe-Post`, no line breaks; added with PHPMailer's `addCustomHeader()` on delivery), `html` (placeholders replaced by HTML that code produced safely, e.g. the Markdown of a campaign – never user input; in the subject their plain text) and `uniqueKey` (job key for per-recipient idempotency). Existing callers are unchanged.

## Permissions

| Permission | Allows |
|---|---|
| `content.view` | Landing pages and blog posts: list, form, preview of drafts and scheduled posts, covers of drafts |
| `content.manage` | Create, edit, publish and delete landing pages and posts |
| `newsletter.view` | Subscriber list and campaigns |
| `newsletter.manage` | Write, test, send and stop campaigns, delete subscribers |
| `newsletter.export` | CSV export of the subscribers |
| `settings.branding` (existing) | Settings → Marketing and the preview image |

The migrations grant all five new permissions to the built-in administrator role.

## Tested against

Everything is covered by feature tests against MariaDB. E-mails are checked in the job queue; delivery with the unsubscribe headers is tested against a local fake SMTP server (`tests/Support/smtp_capture_server.php`). The CAPTCHA provider is mocked (`FakeHttp`). Trustpilot is only linked – no API is called; real delivery to mail providers and their one-click handling was not tested.

## Limitations

* Images are stored as uploaded (no re-encoding; metadata such as EXIF is kept) under `storage/uploads/marketing` – include it in backups.
* Landing pages and posts are single-language (written in the language of the target market); the surrounding website follows the visitor's language.
* Campaign statistics count queued, delivered (accepted by the SMTP server) and failed e-mails; there is no open or click tracking (on purpose).
* Customer e-mail address changes by staff do not update an existing newsletter subscription address.
