# Insights — project notes for Claude

Laravel + Livewire Volt + Flux personal-finance app. Plaid integration for bank
transactions, hierarchical categories (up to 3 levels deep), Chart.js dashboards.

## Environment

- Container: `insights-app` (PHP 8.4-apache). Run artisan/composer/pest via
  `docker exec insights-app ...`, never on the host.
- Vite dev server: `insights-vite`, routed via Traefik at
  `vite.insights.dev.local.test`. App itself at `insights.dev.local.test`.
- `vite.config.js` sets `refresh: ['resources/views/**/*']` — almost any request
  touching a blade file triggers a full browser reload in local dev. If a page
  unexpectedly resets mid-interactive-test (e.g. a filter reverts to defaults),
  check for this before assuming a bug.

## Git workflow

No `local` branch here — commits go directly to `main`. This deviates from the
global default branch model; don't introduce a `local` branch unless asked.

**Commit each finished fix/feature as its own commit, proactively, without
waiting to be asked each time.** If a file already has unrelated uncommitted
changes mixed in with a new fix, ask how to handle it (combine into one commit,
hand-split via patch, or leave uncommitted) rather than guessing.

## Livewire/Chart.js gotchas learned so far

- Chart.js must never be handed live `$wire.*` array references — it recursively
  walks Livewire's reactive proxy trying to diff for animation, causing
  `RangeError: Maximum call stack size exceeded`. Always spread into a plain
  array first (see `resources/views/components/chart.blade.php`).
- After an in-place Livewire-driven chart update (`$wire.$watch` + `chartObj.update()`),
  the canvas can silently revert to Chart.js's default 300x150 size because its
  `ResizeObserver` doesn't always refire after a Livewire DOM morph. Call
  `chartObj.resize()` before `update()` if this happens again elsewhere.
- Inline `<script>` blocks that register `Livewire.on(...)` listeners inside a
  `document.addEventListener('livewire:init', ...)` guard only ever run once, on
  a hard page load. If a page is reachable via `wire:navigate` (soft nav) from
  elsewhere without a prior hard load, that listener never registers. Fix: use
  `@script` / `@endscript` instead (see `admin/linked-accounts/index.blade.php`
  and `components/chart.blade.php` for the working pattern).
- `Category::descendants()` (`app/Models/Category.php`) returns a flat array of
  plain integer ids (including itself), not objects — never wrap it in
  `collect(...)->pluck('id')`, that silently produces an all-null array.

## Active work

See the repo-root `todo` file (untracked scratch file, not committed) for the
current task list. As of 2026-07-18 it covers: three category/chart/Plaid bugs
(fixed), and a planned categorization-UX pass (optimistic save, searchable
category list, merchant-based suggestions — groundwork for future
auto-categorization).
