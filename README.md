# Insights

A Laravel + Livewire application for aggregating and tracking personal financial data across
multiple bank accounts and credit cards using [Plaid](https://plaid.com/).

## Status

Work in progress. Core functionality — account linking, transaction sync, categorization, type
classification, and reporting — is implemented. Autocategorization rules and budgeting tools are
not built yet.

## Features

- **Plaid integration** — link bank/credit accounts via Plaid Link, sync transactions, and mirror
  Plaid's own category taxonomy (`OriginalCategory`, including its `personal_finance_category`
  metadata) alongside your own custom categories.
- **Hierarchical, user-defined categories** — nested categories independent of Plaid's own tree,
  with color coding and a searchable picker.
- **Transaction type classification** — every transaction is tagged `income`, `expense`,
  `transfer`, or `adjustment`, derived automatically from Plaid's category data at sync time (e.g.
  credit card payments are classified as transfers, not expenses, avoiding double-counting).
  Transfers are automatically paired across accounts (opposite sign, similar amount, close dates);
  pairing can also be searched/set/cleared manually from a quick-edit popup on any transaction.
- **Account tracking modes** — mark an account `tracked` (included in aggregate reports),
  `reference` (visible but excluded from totals), or `excluded`. Unlinking an institution soft-closes
  it (reversible) instead of deleting its accounts/transaction history.
- **Reports**, both with configurable date range and granularity (daily/monthly/quarterly/yearly):
  - **Balance / Net Cash** — asset vs. liability snapshot and a net-cash trend chart.
  - **Income / Expense** — income/expense/net snapshot, a trend chart (grouped bars, or a stacked
    area breakdown when filtering to specific categories), and a paginated list of the underlying
    transactions. Filterable by category (multi-select), a simple text search, and an amount range.
- **Transaction Search** — the full transaction list/search view (also embedded per-account),
  filterable by account, category, type, amount range, and date range, with a richer search syntax
  (`required`, `-excluded`, optional terms) and a category-breakdown chart.
- **Bulk actions** — select multiple transactions to assign a category/type or delete at once.
- **Optimistic UI** — category/type edits show instantly and reconcile with the server response.
- Mobile-responsive layout and dark mode throughout.

## Tech Stack

- PHP 8.3+ / Laravel 13
- Livewire 4 / Volt (single-file components)
- Flux UI (free tier)
- Tailwind CSS 4
- Chart.js
- Plaid API
- SQLite (default; configurable via `DB_CONNECTION`)
- Pest (tests), Pint (style), Larastan/PHPStan (static analysis), Rector

## Requirements

- PHP 8.3 or newer, with the extensions Laravel needs by default (BCMath, Ctype, cURL, DOM,
  Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO — plus `pdo_sqlite` for the default database, or
  `pdo_mysql`/etc. if you point `DB_CONNECTION` elsewhere), plus `intl` (used for currency
  formatting)
- Composer 2.x
- Node.js 20 or newer (Tailwind's native CSS engine requires it) and npm
- A Plaid account, **only if you want to link real bank accounts** — see
  [Linking a bank account](#linking-a-bank-account) below for the sandbox-vs-production
  distinction. Not needed at all if you're just exploring — see
  [Exploring without a Plaid account](#exploring-without-a-plaid-account).

## Getting Started

The steps below are for running the app **locally** (development, or just trying it out) — hot
reloading, debug output, the works. If you want to actually self-host this for real use, see
[Production deployment](#production-deployment) instead; it starts from the same repo but a
different, hardened setup.

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
docker exec -u www-data insights-app touch database/database.sqlite
docker exec -u www-data insights-app php artisan migrate
```

The app is now at **http://localhost:8000**. The `vite` container installs its own dependencies
and runs `npm run dev` automatically (see its `command:` in `docker-compose.yml`), giving you
hot-reloading CSS/JS with no separate step.

Every command above runs as `-u www-data` (Apache's own user inside the container, remapped to
match your host user — see `docker/setup-dev-container.sh`) instead of the `docker exec` default
of root, so nothing ends up root-owned on disk where `www-data` can't write to it later. `-e
HOME=/tmp` is only needed for `composer` (it wants a writable home directory for its cache;
`www-data` doesn't have one by default).

### Option B — Docker with your own reverse proxy (Traefik, nginx, etc.)

Use the base `docker-compose.yml` as-is (skip the override file above) and point your reverse
proxy at the `app` service (port 80) and `vite` service (port 5173) on whatever hostname you like.
If you use Traefik with an external network named `web`, the existing labels will pick it up
automatically. Set `VITE_HMR_HOST` (and `VITE_HMR_CLIENT_PORT` if your proxy isn't on port 80) in
`.env` to your chosen hostname so Vite's hot-reload websocket connects correctly — see the comments
in `.env.example`.

### Option C — Bare metal (no Docker)

```bash
git clone <this-repo> insights && cd insights
cp .env.example .env
composer install
npm install
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Start everything (web server, queue worker, log tailer, and Vite) with:

```bash
composer run dev
```

The app will be at whatever `php artisan serve` reports (default `http://localhost:8000`).

## Production deployment

Everything in [Getting Started](#getting-started) above is a **development** setup — hot-reloading
Vite, debug output on, `docker-compose.yml`'s image built from `docker/setup-dev-container.sh`
(SSH server, Node/Playwright for browser tests, `opcache` disabled). None of that belongs in a
real deployment. Use this instead.

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
docker compose -f docker-compose.prod.yml up -d
```

`docker-compose.prod.yml` builds a separate, lean image (`docker/Dockerfile.prod`) — no Node, no
SSH, no dev-only PHP extensions, `npm run build`'s compiled assets baked in at build time instead
of a live Vite dev server, `opcache` on. Starting it runs pending migrations automatically and
persists the database in a named volume (`insights-database`), so `docker compose down` /
`docker compose up -d` again (or rebuilding the image for an update) doesn't lose data.

It also starts a second `scheduler` service running `php artisan schedule:work` — required for
this app's scheduled Plaid sync (`transactions:pull`, every 10 days — see `routes/console.php`) to
actually fire on its own. Without it, syncing only happens when you manually click "Pull Data".

**Never re-run `key:generate` against a database that already has data** — `linked_accounts.access_token`
is encrypted with `APP_KEY`; rotating it makes every existing linked account's stored token
permanently unreadable. Generate it once, before the first `up -d`, and keep it.

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
touch database/database.sqlite   # first deploy only, if using the default sqlite driver
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

## Contributing

Want to run the test suite, work on a fix, or open a PR? See [CONTRIBUTING.md](CONTRIBUTING.md).

## Notes

This project is actively evolving; some routes and UI components may still change.

## License

Licensed under [AGPL-3.0](LICENSE). In short: you're free to use, modify, and self-host this —
including commercially — but if you distribute a modified version or run it as a network service,
you have to make that version's source available under the same license too. Genuinely separate
add-ons that merely integrate with this app (not modifications to it) aren't required to be
AGPL — see [CONTRIBUTING.md](CONTRIBUTING.md) if you're contributing.
