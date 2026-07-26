# Insights

[![CI](https://github.com/loki495/insights/actions/workflows/ci.yml/badge.svg)](https://github.com/loki495/insights/actions/workflows/ci.yml)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)

A Laravel + Livewire application for aggregating and tracking personal financial data across
multiple bank accounts and credit cards using [Plaid](https://plaid.com/).

## Screenshots

| Dashboard | Transaction Search |
| --- | --- |
| ![Dashboard](docs/screenshots/dashboard.png) | ![Transaction Search with chart and filters expanded](docs/screenshots/report.png) |

| Dark mode | Mobile |
| --- | --- |
| ![Dark mode](docs/screenshots/dark-mode.png) | ![Mobile view](docs/screenshots/mobile.png) |

All captured against the seeded demo dataset — see [Exploring without a Plaid
account](docs/SETUP.md#exploring-without-a-plaid-account).

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
- **Dashboard** — a trailing-90-day net cash trend, a "Spending This Month" category breakdown,
  and a recent-transactions feed, alongside per-account balance cards grouped by institution.
- **Reports**, both with configurable date range and granularity (daily/monthly/quarterly/yearly):
  - **Balance / Net Cash** — asset vs. liability snapshot and a net-cash trend chart.
  - **Income / Expense** — income/expense/net snapshot, a trend chart (grouped bars, or a stacked
    area breakdown when filtering to specific categories), and a paginated list of the underlying
    transactions. Filterable by category (multi-select), a simple text search, and an amount range.
- **Transaction Search** — the full transaction list/search view (also embedded per-account),
  filterable by account, category, type, amount range, and date range, with a richer search syntax
  (every word must match — prefix with `-` to exclude instead) and a category-breakdown chart.
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
- SQLite (default) or MySQL via `DB_CONNECTION` — both are exercised in CI (see
  `.github/workflows/ci.yml`'s `test`/`test-mysql` jobs). Postgres should work (Laravel supports
  it natively) but isn't CI-tested yet, so treat it as unverified.
- Pest (tests), Pint (style), Larastan/PHPStan (static analysis), Rector

## Quick Start

Full setup instructions — all install options, production deployment, and Plaid credential setup —
live in **[docs/SETUP.md](docs/SETUP.md)**. The short version, via Docker (no Plaid account
needed to look around, see below):

```bash
git clone <this-repo> insights && cd insights
cp .env.example .env
cp docker-compose.override.yml.example docker-compose.override.yml
docker compose up -d
docker exec -u www-data -e HOME=/tmp insights-app composer install
docker exec -u www-data insights-app php artisan key:generate
docker exec -u www-data insights-app touch storage/app/database.sqlite
docker exec -u www-data insights-app php artisan migrate
```

The app is now at **http://localhost:8000**. No Plaid account needed to explore it — seed the demo
dataset instead:

```bash
docker exec -u www-data insights-app php artisan db:seed --class=DemoDataSeeder
```

That creates a `test@example.com` / `password` login with realistic sample data — see [Exploring
without a Plaid account](docs/SETUP.md#exploring-without-a-plaid-account) for details, or
[docs/SETUP.md](docs/SETUP.md) generally for bare-metal setup, running behind your own reverse
proxy, and **production deployment** (a different, hardened setup from the above).

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
