# Setup & Deployment

Full reference for running Insights — local development, production self-hosting, and the
Plaid credential setup either one needs. See the [README](../README.md) for a quick project
overview; this doc is the detailed operator's manual.

- [Requirements](#requirements)
- [Production deployment](#production-deployment)
- [Getting Started (local development)](#getting-started-local-development)
- [Exploring without a Plaid account](#exploring-without-a-plaid-account)
- [Password reset / mail delivery](#password-reset--mail-delivery)
- [Linking a bank account](#linking-a-bank-account)

## Requirements

- PHP 8.3 or newer, with the extensions Laravel needs by default (BCMath, Ctype, cURL, DOM,
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

### Docker

```bash
cp .env.example .env
```

Edit `.env`: at minimum set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to your real
domain, and your Plaid credentials (see [Linking a bank account](#linking-a-bank-account)). Leave
`APP_KEY` blank for now. Optionally set `LOG_CHANNEL=stderr` so application errors show up in
`docker logs` alongside PHP's own error log (already routed to stderr). Optionally set
`APP_PORT=9000` (or similar) to change the port the container publishes — defaults to 8000.

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
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
git clone <this-repo> insights && cd insights
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
git clone <this-repo> insights && cd insights
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
in `.env.example`.

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
git clone <this-repo> insights && cd insights
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

## Password reset / mail delivery

The "Forgot your password?" link on the login page is live and works out of the box, but
`.env.example` defaults `MAIL_MAILER=log` — no real email ever gets sent, the reset link is
written to `storage/logs/laravel.log` (or, in the Docker setups, `docker/logs/laravel.log`)
instead. Fine for local development, but a problem for a real deployment: if you lock yourself out
without configuring real mail delivery first, digging the reset link out of a log file is your only
way back in.

For a real deployment, set `MAIL_MAILER` to a real driver (`smtp`, or a transactional-email
provider Laravel supports) and fill in the matching `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/
`MAIL_PASSWORD`/`MAIL_FROM_ADDRESS` values in `.env` — see [Laravel's mail
documentation](https://laravel.com/docs/mail) for the full list of supported drivers and their
config options.

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
