# Insights

A Laravel + Livewire application for aggregating and tracking personal financial data across multiple bank accounts and credit cards using Plaid.

## Status
Work in progress — core functionality, category-based reporting, and interactive charts are implemented; UI refinements, budgeting tools, and autocategorization rules are still in progress.

## Overview
Insights is designed to centralize financial data from multiple sources and provide a clear view of spending, balances, and trends.

## Tech Stack
- Laravel 12
- Livewire 4 / Volt
- Flux UI
- Tailwind CSS 4
- Chart.js
- Plaid API
- SQLite (default; configurable via `DB_CONNECTION`)

## Features
- Secure Plaid integration for bank and credit card account syncing
- Multi-account aggregation
- Transaction tracking and categorization, including a hierarchical mirror of Plaid's own category tree (`OriginalCategory`) with personal-finance-category metadata
- Custom user-defined categories, separate from the Plaid-provided ones
- Interactive spending reports with category drill-down charts (Chart.js)
- Backend structure designed for extensibility (budgets, analytics, etc.)

## Planned Improvements
- Autocategorization rules for incoming transactions
- Budgeting and forecasting tools
- Improved UI/UX and reporting dashboards

## Why I Built This
To consolidate personal finances in a single *free* app, with flexible categorization and customized insights

## Development
This project runs in Docker with Traefik-based local routing:
```
docker compose up -d
```
The app is served at `insights.dev.local.test` and Vite at `vite.insights.dev.local.test` (requires the shared external `web` Traefik network and host entries for those domains).

## Notes
This project is actively evolving. Some routes and UI components are incomplete or subject to change.
