# Installation

Nebrix runs as a PHP web application plus two CLI processes (queue worker and scheduler) on a MariaDB/MySQL database. You can install it with Docker Compose or directly on a server.

> Upgrading an existing RED-Host installation? Read [UPGRADE_GUIDE.md](UPGRADE_GUIDE.md) first.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.3 or 8.4 (CLI and web) |
| PHP extensions | `pdo_mysql`, `sodium`, `curl`, `mbstring`, `openssl`, `json`, `fileinfo`, `dom` (PDF and e-invoices); recommended: `opcache`, `intl`, `gd` |
| Database | MariaDB 10.6+ (tested with 11.4) or MySQL 8.0+ |
| Web server | Apache 2.4 with `mod_rewrite` (configuration included) or nginx + PHP-FPM |
| Composer | 2.x |
| TLS | Required in production (sessions use `Secure`/`__Host-` cookies on HTTPS) |

The document root **must** be the `public/` directory. Never expose the project root.

## Option A – Docker Compose

```bash
git clone https://github.com/Shvquu/nebrix-panel.git
```

```bash
cd nebrix-panel && cp .env.example .env
```

1. Edit `.env`: set `APP_URL`, `DB_DATABASE`, `DB_USERNAME` and a strong `DB_PASSWORD`.
2. Build the image and generate an application key:

```bash
docker compose build
```

```bash
docker compose run --rm --no-deps app php bin/nebrix key:generate
```

   Copy the printed value into `APP_KEY` in `.env` and **store a backup of it outside the server**. Without it, stored credentials (SMTP, payment keys, integration tokens, 2FA secrets) cannot be decrypted.

3. Start the stack and run the migrations:

```bash
docker compose up -d
```

```bash
docker compose exec app php bin/nebrix migrate
```

   Until the migrations have run, `worker` and `scheduler` log "table … doesn't exist" and restart; that is expected and stops once the schema exists.

4. Create the first super administrator:

```bash
docker compose exec app php bin/nebrix user:create-admin --email=admin@example.com
```

   The command asks for a password (at least 10 characters, checked against the password policy).

5. Put a TLS-terminating reverse proxy (nginx, Caddy, Traefik, …) in front of port `APP_PORT` (default 8080) and add the proxy's address to `TRUSTED_PROXIES`.

The image is built from `.docker/Dockerfile`; `.dockerignore` keeps `.env`, `.git`, local `vendor/`, tests, logs and runtime data out of the build context, so no secrets end up in the image. The compose file starts five services: `app` (Apache + PHP 8.4, runs as `www-data` on port 8080), `worker` (`queue:work`), `scheduler` (`schedule:run` every 60 seconds), `console` (WebSocket proxy for server consoles on `127.0.0.1:8081`) and `db` (MariaDB, not published to the host). Uploaded branding files are kept in the `uploads` volume, issued invoice documents in `invoices` (mounted into app, worker and scheduler – include it in your backups and keep it for the statutory retention period), the database in `db-data`.

Reverse proxy: route everything to port 8080 and the console WebSocket path (`CONSOLE_WS_URL`, default `/console/ws`) to port 8081 with WebSocket upgrade (the proxy's address as seen by the containers – usually the Docker gateway – belongs in `TRUSTED_PROXIES`), e.g. nginx:

```nginx
location /console/ws { proxy_pass http://127.0.0.1:8081; proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_read_timeout 4h; access_log off; }
location / { proxy_pass http://127.0.0.1:8080; proxy_set_header Host $host; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_set_header X-Forwarded-Proto https; }
```

## Option B – Manual installation

```bash
git clone https://github.com/Shvquu/nebrix-panel.git /var/www/nebrix
```

```bash
cd /var/www/nebrix && composer install --no-dev --optimize-autoloader --classmap-authoritative
```

```bash
cp .env.example .env && chmod 640 .env
```

1. Create a database and a user with privileges on that database only:

```sql
CREATE DATABASE nebrix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nebrix'@'localhost' IDENTIFIED BY '<strong password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON nebrix.* TO 'nebrix'@'localhost';
```

   `DROP` is only needed if you want `migrate` to be able to run future migrations that drop indexes; the current migrations do not drop tables.

2. Fill in `.env` (see [CONFIGURATION.md](CONFIGURATION.md)) and generate the key with `php bin/nebrix key:generate`.
3. Run `php bin/nebrix migrate` and `php bin/nebrix user:create-admin --email=…`.
4. File permissions – the web server and CLI user (e.g. `www-data`) needs write access only to `storage/` (including the invoice archive `storage/invoices`, or the directory in `INVOICE_ARCHIVE_PATH`) and `public/uploads/`:

```bash
chown -R root:www-data /var/www/nebrix && chown -R www-data:www-data storage public/uploads && chmod 640 .env
```

5. Web server:
   * **Apache**: use `.docker/apache/nebrix.conf` as a template (document root `public/`, PHP disabled in `public/uploads`). `public/.htaccess` routes all requests to `index.php`.
   * **nginx**: route everything that is not a file to `index.php` and deny PHP in uploads:

```nginx
root /var/www/nebrix/public;
index index.php;
location / { try_files $uri /index.php$is_args$args; }
location ^~ /uploads/ { location ~ \.(php|phtml|phar|html?|svg)$ { deny all; } }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $realpath_root/index.php; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
location ~ \.php$ { deny all; }
location ~ /\. { deny all; }
```

6. Background processes – run the worker under a process supervisor and the scheduler from cron:

```ini
# /etc/systemd/system/nebrix-worker.service
[Unit]
Description=Nebrix queue worker
After=network.target mariadb.service

[Service]
User=www-data
WorkingDirectory=/var/www/nebrix
ExecStart=/usr/bin/php bin/nebrix queue:work --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
echo '* * * * * www-data cd /var/www/nebrix && php bin/nebrix schedule:run >> /dev/null 2>&1' > /etc/cron.d/nebrix
```

   The worker exits after `--max-time` seconds (default 3600) so that code updates and memory are refreshed; the supervisor restarts it.

7. Console proxy (only needed for Proxmox consoles) – a second unit with `ExecStart=/usr/bin/php bin/nebrix console:proxy --listen=127.0.0.1:8081`, and the reverse proxy forwards `/console/ws` to it (see the nginx example under Option A).

8. Optional hardening: `php bin/nebrix db:triggers --install` creates triggers that reject changes to payment events, audit log and credit ledger (needs the `TRIGGER` privilege).

## Option C – Plesk

Intended set-up (not yet verified on a live Plesk server; menu names can differ slightly between Plesk versions): Plesk Obsidian with Apache + nginx (default), PHP 8.4 (or 8.3) from Plesk, MariaDB. Replace `panel.example.com` with your (sub)domain; the commands run as the subscription's system user over SSH.

1. **Domain and TLS**: *Websites & Domains → Add Subdomain* (e.g. `panel.example.com`). Issue a Let's Encrypt certificate (*SSL/TLS Certificates*) and enable the permanent HTTP → HTTPS redirect (*Hosting & DNS → Hosting*).
2. **PHP**: *Hosting & DNS → PHP*: PHP 8.4, handler *FPM application served by Apache* (so `.htaccess` applies). Under *Tools & Settings → PHP Settings → 8.4* check that `pdo_mysql`, `sodium`, `curl`, `mbstring`, `openssl`, `fileinfo`, `dom`, `intl` and `opcache` are enabled. `memory_limit` 256M is enough.
3. **SSH access**: *Hosting & DNS → Hosting → SSH access*: `/bin/bash` (not the chrooted shell – it has no PHP). Use the Plesk PHP binary for every command: `/opt/plesk/php/8.4/bin/php`.
4. **Code**: install with the Plesk *Git* extension (repository `https://github.com/Shvquu/nebrix-panel.git`, your release branch, deployment path e.g. `/nebrix`) or over SSH:

```bash
cd /var/www/vhosts/example.com/panel.example.com && git clone https://github.com/Shvquu/nebrix-panel.git nebrix
```

   Then set the **document root** to `nebrix/public` (*Hosting & DNS → Hosting → Document root*). Everything outside `public/` (`.env`, `src/`, `storage/`) is then not reachable from the web.
5. **Dependencies** (Plesk ships Composer for its *PHP Composer* extension; you can also use that extension's UI):

```bash
cd /var/www/vhosts/example.com/panel.example.com/nebrix && /opt/plesk/php/8.4/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader --classmap-authoritative
```

   (On AlmaLinux/RHEL-based Plesk servers the path is `/usr/lib64/plesk-9.0/composer.phar`.)
6. **Database**: *Databases → Add Database* (MariaDB, utf8mb4) with its own user.
7. **Configuration**:

```bash
cp .env.example .env && chmod 600 .env && /opt/plesk/php/8.4/bin/php bin/nebrix key:generate
```

   In `.env`: `APP_URL=https://panel.example.com`, `APP_KEY=` (the generated value – keep a copy outside the server), `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Leave `TRUSTED_PROXIES` empty: Plesk's nginx passes the client address to Apache. If the login history shows `127.0.0.1` for every sign-in, set `TRUSTED_PROXIES=127.0.0.1`.
8. **Schema and first administrator**:

```bash
/opt/plesk/php/8.4/bin/php bin/nebrix migrate && /opt/plesk/php/8.4/bin/php bin/nebrix user:create-admin --email=admin@example.com
```

9. **Scheduler and worker** (*Websites & Domains → Scheduled Tasks → Add Task → Run a command*, schedule `* * * * *`, "notify" off):

```bash
cd /var/www/vhosts/example.com/panel.example.com/nebrix && /opt/plesk/php/8.4/bin/php bin/nebrix schedule:run
```

```bash
cd /var/www/vhosts/example.com/panel.example.com/nebrix && /opt/plesk/php/8.4/bin/php bin/nebrix queue:work --max-time=55
```

   The second task starts a worker every minute that stops after 55 seconds, so e-mails, provisioning and Discord roles are processed continuously without a process supervisor. Several workers at once are safe (jobs are reserved atomically). With root access you can instead run the worker as a systemd service (see Option B, `User=` = the subscription's system user, `ExecStart=/opt/plesk/php/8.4/bin/php bin/nebrix queue:work --max-time=3600`).
10. **E-mail with the Plesk mail server**: create a mailbox (*Mail → Create Email Address*, e.g. `billing@example.com`). Under *Admin → Settings → E-mail*: host `127.0.0.1`, port `587` with STARTTLS (enable *SMTP service on port 587* under *Tools & Settings → Mail Server Settings*) or port `465` with SMTPS, user name = the **full** e-mail address, its password. If Plesk's mail server has no certificate for the name you connect to (typical without a domain), switch off *Verify the TLS certificate of the SMTP server* – this is only possible for `localhost`/`127.0.0.1`. Port 25 without encryption usually fails: Postfix only accepts logins over TLS. *Test connection* shows the server's answer (e.g. `535 … authentication failed` = wrong user name or password).
11. **File permissions**: Plesk runs PHP as the subscription user, which owns the files – `storage/` and `public/uploads/` are writable without changes. `public/uploads/.htaccess` blocks scripts in the upload folder.
12. **Console proxy** (only for Proxmox consoles, needs root): a systemd service for `bin/nebrix console:proxy --listen=127.0.0.1:8081` and under *Apache & nginx Settings → Additional nginx directives*:

```nginx
location /console/ws { proxy_pass http://127.0.0.1:8081; proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_read_timeout 4h; access_log off; }
```

**Without a domain (IP address only)** – fine for trying it out, not for production:
* Open the panel by IP: *Tools & Settings → IP Addresses* → the IP → *Default site* = your subscription. Set `APP_URL` exactly to the address you open, e.g. `http://203.0.113.10` (the Origin check compares it), and leave `SESSION_SECURE_COOKIE` commented out (it follows `APP_URL`; with `true` the browser drops the login cookie on plain HTTP) and set `APP_HSTS=false`.
* Plain HTTP sends passwords unencrypted; HTTPS on a bare IP only works with a self-signed certificate (browser warning). Payment webhooks (Stripe, PayPal) need a public HTTPS URL, and e-mails from a server without a domain (no SPF/DKIM, no matching reverse DNS) are usually rejected or end up as spam. Use a domain (with Let's Encrypt) before going live; until then an external SMTP account is the more reliable way to send e-mails.

**Updates**: pull the new version (Plesk *Git* → *Pull updates*, or `git pull`), then run Composer (step 5) and `bin/nebrix migrate`; workers pick up the new code within a minute. In the Git extension you can enter both commands as *additional deployment actions*.

**Backups**: Plesk backups contain the files (including `storage/invoices` and `.env`) and the database. Keep a separate copy of `APP_KEY` – without it stored secrets cannot be decrypted.

## After the installation

1. Sign in at `APP_URL/login` with the administrator account and enable two-factor authentication (Account → Security).
2. Admin → Settings: company data, branding, SMTP (send a test e-mail), billing defaults, payment providers.
3. Admin → Integrations: add Proxmox / Pterodactyl / Nextcloud / TeamSpeak connections and run the connection test.
4. Admin → Products: create products and link them to an integration.
5. Configure the payment webhooks (see [PAYMENTS.md](PAYMENTS.md)).
6. Check `GET /health` (returns `{"status":"ok"}` when the database is reachable) from your monitoring.

## Verifying an installation

```bash
php bin/nebrix migrate:status
```

```bash
php bin/nebrix integrations:check
```

```bash
php bin/nebrix schedule:run --force
```

Admin → System shows the PHP version, required extensions, pending migrations, queue state and the last scheduler runs.
