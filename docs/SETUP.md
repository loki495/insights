# Setup & Deployment

Full reference for running Insights — local development, production self-hosting, and the
Plaid credential setup either one needs. See the [README](../README.md) for a quick project
overview; this doc is the detailed operator's manual.

- [Requirements](#requirements)
- [Production deployment](#production-deployment)
- [Getting Started (local development)](#getting-started-local-development)
- [Exploring without a Plaid account](#exploring-without-a-plaid-account)
- [Password reset / mail delivery](#password-reset--mail-delivery)
- [Backup, restore, and upgrades](#backup-restore-and-upgrades)
- [Hosting a disposable public demo](#hosting-a-disposable-public-demo)
- [Maintenance commands](#maintenance-commands)
- [Linking a bank account](#linking-a-bank-account)

## Requirements

- PHP 8.5 or newer, with the extensions Laravel needs by default (BCMath, Ctype, cURL, DOM,
  Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO — plus `pdo_sqlite` for the default database, or
  `pdo_mysql` if you point `DB_CONNECTION` at MySQL instead), plus `intl` (used for currency
  formatting)
- Composer 2.x
- Node.js 20 or newer (Tailwind's native CSS engine requires it) and npm
- A Plaid account, **only if you want to link real bank accounts** — see
  [Linking a bank account](#linking-a-bank-account) below for the sandbox-vs-production
  distinction. Not needed at all if you're just exploring — see
  [Exploring without a Plaid account](#exploring-without-a-plaid-account).

## Production deployment

This is the setup for actually self-hosting Insights for real use (the README's Quick Start is
the condensed version of the Docker steps below). It's a genuinely different, hardened build from
local development, below — no hot-reloading, no debug output, a lean production image.

> **Before exposing this to the internet:** registration is unrestricted by default — anyone who
> can reach the URL can create their own account, and this app has no invite code or
> first-user-only gate. If you seeded the `test@example.com` / `password` demo login (see
> [Quick Start](../README.md#quick-start) or
> [Exploring without a Plaid account](#exploring-without-a-plaid-account)), delete that user before
> going live — it's a known, public default password. Put the app behind an auth proxy, restrict
> `/register` once you've created your own account, or both; nothing here does it for you.

### Docker

```bash
cp .env.example .env
```

Edit `.env`: at minimum set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to your real
domain, and your Plaid credentials (see [Linking a bank account](#linking-a-bank-account)). Leave
`APP_KEY` blank for now. Optionally set `LOG_CHANNEL=stderr` so application errors show up in
`docker logs` alongside PHP's own error log (already routed to stderr). Optionally set
`APP_PORT=9000` (or similar) to change the port the container publishes — defaults to 8000 on `127.0.0.1` only.
Keep that loopback binding when a reverse proxy runs on the host. Set `APP_BIND_ADDRESS`
explicitly only when another host must reach the app, and restrict access with a firewall and
TLS/authentication proxy. Never expose the sample-data login on a deployment holding real data.
Behind a reverse proxy that terminates TLS, set `TRUSTED_PROXIES` to the address the proxy
connects from (for a proxy on the host, the Compose network's subnet, shown by
`docker network inspect insights_default`), so the app sees the real client IP and https. Never `*`.

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm --entrypoint "" app php artisan key:generate --show
# paste the output into .env's APP_KEY=, then:
docker compose -f docker-compose.prod.yml up -d --wait
```

`docker-compose.prod.yml` builds a separate, lean image (`docker/Dockerfile.prod`) — no Node, no
SSH, no dev-only PHP extensions, `npm run build`'s compiled assets baked in at build time instead
of a live Vite dev server, `opcache` on. Starting it runs pending migrations automatically and
persists the database in a named volume (`insights-database`), so `docker compose down` /
`docker compose up -d` again (or rebuilding the image for an update) doesn't lose data. `--wait`
blocks until the healthcheck passes (i.e. migrations have actually finished) rather than returning
as soon as the container starts.

It also starts a second `scheduler` service running `php artisan schedule:work` — required for
this app's scheduled Plaid sync (`transactions:pull`, checked hourly — see `routes/console.php`)
to actually fire on its own. Without it, syncing only happens when you manually click "Pull Data".
Auto-pull itself is a per-institution setting on the Linked Institutions page (off by default for
newly-linked institutions, with a configurable "every N hours/days" interval) — the hourly schedule
just checks which institutions are actually due.

**Never re-run `key:generate` against a database that already has data** — `linked_accounts.access_token`
is encrypted with `APP_KEY`; rotating it makes every existing linked account's stored token
permanently unreadable. Generate it once, before the first `up -d`, and keep it.

**Upgrading an existing deployment from before the sqlite location moved:** older versions of
this file mounted `insights-database` over the whole `database/` directory instead of
`storage/app` — that directory-wide mount silently shadowed `database/migrations` after the first
boot, so new migrations shipped in later updates never actually reached the running container.
The volume itself doesn't need to change (`database.sqlite` already sits at its root either way,
since both the old and new mount points are directory mounts) — just pull this update, rebuild,
and `up -d` as usual; your existing `insights-database` volume is picked up at the new mount point
automatically. You'll end up with a few harmless unused leftover files (`migrations/`,
`factories/`, `seeders/`, `.gitignore`) sitting alongside `database.sqlite` in the volume from the
old directory structure — safe to ignore, or delete via
`docker compose -f docker-compose.prod.yml exec app sh -c 'cd storage/app && rm -rf migrations factories seeders .gitignore'`
if you'd rather clean them up.

### Bare metal

```bash
git clone https://github.com/loki495/insights.git insights && cd insights
cp .env.example .env
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Edit `.env` as described above (`APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, Plaid
credentials), generate a real key once (`php artisan key:generate`, only on a database with no
data in it yet), then:

```bash
touch storage/app/database.sqlite   # first deploy only, if using the default sqlite driver
php artisan migrate --force
```

This app doesn't prescribe a specific web server or process supervisor — deploying a Laravel app
behind nginx/Apache + php-fpm (or Apache + mod_php) is well-trodden, standard ground; see
[Laravel's own deployment docs](https://laravel.com/docs/deployment) if you're new to it. Two
things specific to this app, though:

- Point your web server's document root at `public/`, same as any Laravel app.
- Register the scheduler: this app has no queue jobs (nothing implements `ShouldQueue`, so
  `QUEUE_CONNECTION` is unused), but it does have a scheduled task. Add one cron entry:
  ```
  * * * * * cd /path/to/insights && php artisan schedule:run >> /dev/null 2>&1
  ```
  Laravel's scheduler checks internally what's actually due each minute — you don't need a
  separate cron line per scheduled command.

## Getting Started (local development)

Everything above is a **production** setup. The steps below are instead for **developing**
Insights itself — hot reloading, debug output, the works. Not what you want if you're just
trying to self-host this for real use (see [Production deployment](#production-deployment)
above); this is for contributing to the app's own code.

Pick whichever setup matches how you like to work. All three end up in the same place: a
migrated database and the app running locally — Plaid credentials in `.env` are only needed once
you're ready to actually link an account (see below).

### Option A — Docker (recommended)

```bash
git clone https://github.com/loki495/insights.git insights && cd insights
cp .env.example .env
```

The base `docker-compose.yml` routes through a Traefik reverse proxy on a custom local domain —
one supported option, not a requirement. If you don't already run Traefik, get direct port access
instead:

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
```

Then:

```bash
docker compose up -d
docker exec -u www-data -e HOME=/tmp insights-app composer install
docker exec -u www-data insights-app php artisan key:generate
docker exec -u www-data insights-app touch storage/app/database.sqlite
docker exec -u www-data insights-app php artisan migrate
```

The app is now at **http://localhost:8000**. The `vite` container installs its own dependencies
and runs `npm run dev` automatically (see its `command:` in `docker-compose.yml`), giving you
hot-reloading CSS/JS with no separate step.

Every command above runs as `-u www-data` (Apache's own user inside the container) instead of the
`docker exec` default of root, so nothing ends up root-owned on disk where `www-data` can't write
to it later. `-e HOME=/tmp` is only needed for `composer` (it wants a writable home directory for
its cache; `www-data` doesn't have one by default).

`www-data`'s UID/GID inside the container default to 1000/1000 (see
`docker/setup-dev-container.sh`), which matches the first user on most Linux installs. If `id -u` /
`id -g` on your host give different numbers (common on macOS, or a non-first Linux user account),
set `HOST_UID`/`HOST_GID` in your `.env` to match before building — otherwise files the container
writes into the bind-mounted repo (`storage/`, `vendor/`, etc.) end up owned by a UID/GID your host
user can't write to:

```bash
echo "HOST_UID=$(id -u)" >> .env
echo "HOST_GID=$(id -g)" >> .env
docker compose up -d --build
```

### Option B — Docker with your own reverse proxy (Traefik, nginx, etc.)

Use the base `docker-compose.yml` as-is (skip the override file above) and point your reverse
proxy at the `app` service (port 80) and `vite` service (port 5173) on whatever hostname you like.
If you use Traefik with an external network named `web`, the existing labels will pick it up
automatically. Set `VITE_HMR_HOST` (and `VITE_HMR_CLIENT_PORT` if your proxy isn't on port 80) in
`.env` to your chosen hostname so Vite's hot-reload websocket connects correctly — see the comments
in `.env.example`. Set `TRUSTED_PROXIES` to the proxy's address or network if it terminates TLS.

### Remote access via Cloudflare Tunnel

If you expose this app publicly through a tunnel (e.g. Cloudflare Tunnel) alongside its normal
LAN/reverse-proxy setup, the public hostname can't reach the local Vite dev server. Set
`STATIC_ASSET_HOSTS` in `.env` to that public hostname (comma-separated if there's more than one) —
`App\Http\Middleware\UseStaticAssetsForRemoteHost` then forces the built manifest and `Secure`
session cookies for requests to those hosts specifically, while the LAN hostname keeps live
hot-reloading as usual. Run `npm run build` whenever frontend assets change, since the remote
hostname always serves from `public/build`, never the dev server.

### Option C — Bare metal (no Docker)

```bash
git clone https://github.com/loki495/insights.git insights && cd insights
cp .env.example .env
composer install
npm install
php artisan key:generate
touch storage/app/database.sqlite
php artisan migrate
```

Start everything (web server, queue worker, log tailer, and Vite) with:

```bash
composer run dev
```

The app will be at whatever `php artisan serve` reports (default `http://localhost:8000`).

## Exploring without a Plaid account

Want to look around before setting up anything with Plaid? Seed a demo dataset instead — a "Demo
Bank" institution with checking/savings/credit-card accounts, ~6 months of randomized but
realistic transactions (paychecks, groceries, rent, a couple of paired transfers), and some
transactions left deliberately uncategorized:

```bash
docker exec -u www-data insights-app php artisan db:seed --class=DemoDataSeeder
# production Docker (no fixed container name — use the compose service name instead):
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=DemoDataSeeder --force
# bare metal:
php artisan db:seed --class=DemoDataSeeder
```

This creates (or reuses) a `test@example.com` / `password` login. It's not part of the default
`db:seed` run, so it never runs against a real user's database by accident. The demo institution's
"Pull Data" button is hidden — there's no real Plaid item behind it, so pulling would just fail.

## Backup, restore, and upgrades

These commands apply to the default SQLite production Compose installation. They use the
existing Compose project and its named volume; run them from the same checkout and with the
same project name as installation. For an external MySQL database, use its native consistent
backup/restore procedure instead of treating the SQLite archive as a database backup.

### Back up before an update

Save the current commit (`git rev-parse HEAD`), image identity
(`docker compose -f docker-compose.prod.yml images`), and your `.env` in private backup storage.
The **original `APP_KEY` is required** to decrypt stored Plaid tokens after a restore. Store the
configuration and backup securely outside the public repository; neither belongs in an issue.

Stop both writers before archiving SQLite, including any journal/WAL files:

```bash
umask 077
backup_dir="$(mktemp -d "${TMPDIR:-/tmp}/insights-backup.XXXXXX")"
cp .env "$backup_dir/environment.env"
docker compose -f docker-compose.prod.yml stop scheduler app
docker compose -f docker-compose.prod.yml run --rm --no-deps -T --entrypoint tar app \
  -C /var/www/html/storage/app -czf - . > "$backup_dir/storage-app.tar.gz"
tar -tzf "$backup_dir/storage-app.tar.gz" >/dev/null
docker compose -f docker-compose.prod.yml up -d --wait
```

Check every command succeeds. If archive creation or validation fails, restart the existing
services and fix the backup before upgrading. Copy the backup directory to durable private
storage: temporary storage is not a retention policy. The archive contains sensitive financial
data and encrypted tokens; the environment file contains the encryption key and credentials.

### Upgrade

Read the target version's release notes and retain the old image/commit for recovery. After a
verified backup, stop `scheduler` and `app`, check out the intended version, then run:

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d --wait
docker compose -f docker-compose.prod.yml exec -u www-data app php artisan migrate:status
```

Startup applies pending migrations. Check login, existing transactions, and a report before
resuming normal use. Do not run `migrate:fresh`, reseed a real database, regenerate `APP_KEY`,
or run `docker compose down -v`: these can destroy data or make tokens unreadable.

### Restore / failed upgrade

Test recovery in a separate Compose project with a fresh volume and an unused loopback port
first. Use the **saved application version and original environment/key**, with real Plaid
credentials removed for the rehearsal and the scheduler stopped. Extract only your own trusted
backup archive, into the fresh volume:

```bash
export COMPOSE_PROJECT_NAME=insights-restore-test APP_PORT=8099
docker compose -f docker-compose.prod.yml run --rm --no-deps -T --entrypoint tar app \
  -C /var/www/html/storage/app -xzf - < /private/path/storage-app.tar.gz
docker compose -f docker-compose.prod.yml up -d --wait app
```

The project name gives the rehearsal its own volume (`insights-restore-test_insights-database`)
instead of extracting over the live one; remove it afterwards with
`docker compose -f docker-compose.prod.yml down -v` while that variable is still set. A real
restore runs the same `tar` command in the live project after stopping `scheduler` and `app` and
moving the failed volume's contents aside, never extracting on top of them.

Verify expected account/transaction counts and login before considering the backup usable.
A database migrated by newer code may not work with older code: roll back the application and
its matching pre-upgrade backup together. Keep the failed volume intact until recovery is
confirmed. Starting `scheduler` against restored real data can contact Plaid; do that only when
you intend to resume synchronization.

## Hosting a disposable public demo

`DEMO_MODE=true` differs from simply running `DemoDataSeeder` in a normal installation:
each visitor receives a separate SQLite copy, selected by an encrypted cookie. Visitors use the
same sample login (`test@example.com` / `password`), but edits are kept in their own database
copy. Registration is disabled. This is disposable sample data, not private financial storage.

Use a dedicated deployment with no real Plaid credentials or real user data. Set:

```dotenv
DEMO_MODE=true
SESSION_DRIVER=file
DEMO_DB_TEMPLATE_PATH=/var/www/html/storage/app/demo-template.sqlite
DEMO_DB_STORAGE_PATH=/var/www/html/storage/app/demo-dbs
```

After the initial migration, prepare the template before allowing visitors:

```bash
docker compose -f docker-compose.prod.yml exec -u www-data app php artisan demo:build-template
```

The scheduler rebuilds the template daily to keep sample transaction dates current and removes
visitor copies whose file modification time is older than 24 hours. Existing visitors may lose
their edits after cleanup; a later request creates a fresh copy. Clearing the demo cookie also
starts a fresh copy. Persistent paths above keep both the template and copies in the mounted
volume. Use HTTPS and monitor disk usage on a public demo; per-visitor copies are not a resource
quota or a substitute for rate limiting at your proxy.

## Password reset / mail delivery

The "Forgot your password?" link on the login page is live and works out of the box, but
`.env.example` defaults `MAIL_MAILER=log` — no real email ever gets sent, the reset link is
written to `storage/logs/laravel.log` instead — the same path in every setup, visible on the host
via the bind mount in dev and readable via `docker exec` in prod. Fine for local development, but
a problem for a real deployment: if you lock yourself out
without configuring real mail delivery first, digging the reset link out of a log file is your only
way back in.

For a real deployment, set `MAIL_MAILER` to a real driver (`smtp`, or a transactional-email
provider Laravel supports) and fill in the matching `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/
`MAIL_PASSWORD`/`MAIL_FROM_ADDRESS` values in `.env` — see [Laravel's mail
documentation](https://laravel.com/docs/mail) for the full list of supported drivers and their
config options.

## Maintenance commands

`transactions:pull` (documented above, via the scheduler) isn't the only artisan command this
app ships — two more exist for one-off/manual maintenance:

- `php artisan transactions:reconcile {linked_account_id} {force?}` — re-reconciles already-saved
  transactions for one linked account against what Plaid currently has, without doing a full sync.
- `php artisan transactions:backfill-types` — classifies type (income/expense/transfer) on
  existing transactions and matches internal transfer pairs. Useful after a schema/logic change
  to that classification, to backfill data that predates it.

## Linking a bank account

Plaid gates API access behind its own developer account, separate from this app entirely — there's
no shared/built-in Plaid key, so every deployment of this app needs its own credentials from
[the Plaid dashboard](https://dashboard.plaid.com/). Which kind you need depends on what you're
doing:

- **Linking your own real accounts** (actually using this app for yourself): you need Plaid
  **production** access — a free sandbox signup alone isn't enough. Plaid requires applying for
  production access (describing your use case; possibly other requirements depending on Plaid's
  current terms) before it'll return real account data. Once approved, set `PLAID_CLIENT_ID`,
  `PLAID_API_KEY_PRODUCTION`, and `PLAID_ENVIRONMENT=production` in `.env`.
- **Trying out the Plaid Link flow itself, or developing/testing Plaid-related code**: a free
  [Plaid sandbox account](https://dashboard.plaid.com/signup) is instant and enough — it returns
  fake institutions/transactions, not real bank data. Set `PLAID_CLIENT_ID` and
  `PLAID_API_KEY_SANDBOX` in `.env`, leave `PLAID_ENVIRONMENT=sandbox`.

Either way, once your `.env` has working credentials: register a user, sign in, and use **Linked
Accounts** to start Plaid Link. In sandbox mode, use any of
[Plaid's test credentials](https://plaid.com/docs/sandbox/test-credentials/) (e.g. username
`user_good`, password `pass_good`) to simulate a real institution.
