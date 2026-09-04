# Insights — project notes for Claude

Laravel + Livewire Volt + Flux personal-finance app. Plaid integration for bank
transactions, hierarchical categories (up to 3 levels deep) with per-user autocategorize
rules, Chart.js dashboards. See the repo root `README.md` for the full feature list and
`docs/ROADMAP.md` for what's planned next.

## Environment

- Container: `insights-app` (PHP 8.5-apache). Run artisan/composer/pest via
  `docker exec insights-app ...`, never on the host.
- **Always add `-u www-data` to every `docker exec insights-app ...` call** (e.g.
  `docker exec -u www-data insights-app php artisan ...`). A bare `docker exec` runs as
  root; anything it writes into `storage/`, `bootstrap/cache/`, or `/tmp` ends up
  root-owned and permanently blocks the real web server (which runs as `www-data`) from
  writing there afterward — surfaces later as `tempnam()` warnings, PHPStan/Rector
  cache-write failures, or a broken page unrelated to whatever you actually changed. Hit
  three separate times in one session before this rule was written down. If one of those
  symptoms shows up, check `find storage bootstrap/cache /tmp -not -user www-data` before
  assuming it's a real bug.
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
- A route-bound optional param (`{category?}`, `{categoryRule?}`) replays on every
  subsequent request against a page component, and an absent segment replays as an
  **empty, unsaved model instance**, not literal `null` — a bare `if ($model)` treats
  that as truthy. Guard with `$model?->id` or `$model && $model->exists` instead (see
  `admin/reports/category/index.blade.php`, `components/transactions.blade.php`,
  `admin/category-rules/edit.blade.php`).
- A nullable typed Livewire property with **no explicit `= null` default**
  (`public ?CategoryRule $categoryRule;`) throws "must not be accessed before
  initialization" partway through a real multi-request `Livewire::test()` sequence —
  PHP typed properties have no implicit default. Always write `= null` explicitly on
  nullable Volt/Livewire component properties.
- `Transaction::created_at` is `Carbon\CarbonInterface` at runtime (actually
  `CarbonImmutable`), not `Illuminate\Support\Carbon` — type-hint accordingly in
  anything consuming it (hit via Rector's automatic null-check-to-`instanceof` rewrite in
  `CategoryRuleCondition::matchesDate()`).

## Autocategorize rules

Per-user rules (`app/Models/CategoryRule.php`) that auto-assign a category to a new,
still-uncategorized transaction at sync time (hooked into
`UpdateAccountTransactionsAction` right after `refreshType()` — never overwrites a
manual categorization). Shape: a rule has one level of condition **groups**
(`CategoryRuleConditionGroup`), each holding plain field/operator/value **conditions**
(`CategoryRuleCondition`). Both the rule's own `match_type` (how its groups combine)
and each group's own `match_type` (how its conditions combine) are independently
`all`/`any` — **not** a fixed AND-within-group/OR-between-groups convention — which is
what lets a rule express both `(X and Y) or Z` and `(X or Y) and Z`. See
`.ai/plans/autocategorize-rules/` for the original design writeup and build history.
The create/edit page (`admin/category-rules/edit.blade.php`) also shows a live list of
the user's own uncategorized transactions the current (possibly unsaved) rule would
match, with a button to apply that one rule to them retroactively right now
(`ApplyCategoryRuleRetroactivelyAction`) — deliberately scoped to the one rule on
screen, not a full backfill-everything pass.

## Account lifecycle

Accounts are disabled rather than soft-deleted so their transactions retain a normal account
relationship. A successful pull reconciles against Plaid `/accounts/get` (not the incomplete
account list on `/transactions/sync`): missing accounts get `disabled_reason=missing_from_provider`
and restore automatically if they return. A user-removed account gets `disabled_reason=manual` and
only a deliberate Plaid Link update may restore it; ordinary pulls must leave it disabled.

## Active work

See the repo-root `todo` file (gitignored, not committed) for the current backlog —
it's kept up to date as work happens, so don't rely on a dated summary here going stale
again. `.ai/plans/` holds file-based state for any multi-session initiative currently
in progress (check `.ai/plans/INDEX.md` first when resuming cold).
