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
- A free [Plaid developer account](https://dashboard.plaid.com/signup) — the sandbox environment
  is enough for local development; you don't need a production Plaid agreement to run this locally

## Getting Started

Pick whichever setup matches how you like to work. All three end up in the same place: a
`.env` with your Plaid sandbox keys, a migrated database, and the app running locally.

### Option A — Docker (recommended)

```bash
git clone <this-repo> insights && cd insights
cp .env.example .env
```

Edit `.env` and fill in `PLAID_CLIENT_ID` and `PLAID_API_KEY_SANDBOX` from your
[Plaid dashboard](https://dashboard.plaid.com/) (leave `PLAID_ENVIRONMENT=sandbox`).

If you don't already run a Traefik reverse proxy (the base `docker-compose.yml` routes through one
on a custom local domain — that's this project's own author's personal setup, not a requirement),
get direct port access instead:

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
```

Then:

```bash
docker compose up -d
docker exec insights-app composer install
docker exec insights-app php artisan key:generate
docker exec insights-app touch database/database.sqlite
docker exec insights-app php artisan migrate
docker exec insights-vite npm install
# docker exec defaults to root, but Apache inside the container runs as
# www-data — fix ownership now so the app can write its own cache/log files:
docker exec insights-app chown -R www-data:www-data storage bootstrap/cache
```

The app is now at **http://localhost:8000**. The `vite` container runs `npm run dev`
automatically (see its `command:` in `docker-compose.yml`), giving you hot-reloading CSS/JS —
just restart it (`docker compose restart vite`) after the `npm install` above so it picks up the
newly-installed dependencies.

> **Troubleshooting:** if you ever see a `tempnam(): file created in the system's temporary
> directory` error after running an `artisan`/`composer` command via `docker exec` (which defaults
> to root), re-run the `chown` command above — it means a command left root-owned files that
> `www-data` can no longer write to.

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

Fill in your Plaid sandbox credentials in `.env` as described above, then start everything
(web server, queue worker, log tailer, and Vite) with:

```bash
composer run dev
```

The app will be at whatever `php artisan serve` reports (default `http://localhost:8000`).

## Linking a bank account

Once the app is running, register a user, sign in, and use **Linked Accounts** to start Plaid
Link. In sandbox mode, use any of
[Plaid's test credentials](https://plaid.com/docs/sandbox/test-credentials/) (e.g. username
`user_good`, password `pass_good`) to simulate a real institution with fake accounts and
transactions.

## Development

```bash
# Run the full test suite
php artisan test
# or, inside Docker (-u www-data avoids leaving root-owned cache files behind):
docker exec -u www-data insights-app php artisan test

# Code style (auto-fixes)
vendor/bin/pint

# Static analysis
vendor/bin/phpstan analyse

# All of the above plus Rector's dry-run and a typo check
composer test
```

## Notes

This project is actively evolving; some routes and UI components may still change.
