# Autocategorize Rules

Approved plan (2026-09-03), copied from `/home/andres/.claude/plans/ethereal-drifting-goose.md`
for durability across sessions. See that file's full text for the complete design — this copy
is the source of truth going forward; edit here, not there.

## Context

`insights` classifies transaction *type* (income/expense/transfer) automatically, but
*category* assignment is entirely manual today. Priority #2 on `todo`. Design research done
2026-07-26. This plan:

- **v1 scope**: rules apply to new/incoming transactions only (Plaid sync). Retroactive
  backfill of existing transactions is a separate follow-up plan later.
- **Condition types**: full set — merchant, amount, account, date, regex (regex is an
  operator on text fields, not its own condition type).
- **UI**: sentence-builder with a live "N matching transactions" preview.
- **Un-adopted category with rules pointing at it**: auto-deactivate those rules, surfaced to
  the user at removal time (Andres's approved recommendation).

## Data model

**`category_rules`** (per-user, not shared like `categories`):
- `user_id` (FK → users, cascade delete)
- `category_id` (FK → categories)
- `name` (string, nullable)
- `match_type` (string: `all` | `any`)
- `priority` (integer, ascending, first match wins)
- `active` (boolean, default true)
- Composite index `(user_id, active, priority)`

**Revision (2026-09-03, mid-build)**: Andres wants one level of AND/OR grouping —
`"(X and Y) or Z"` — not the flat-only model originally recommended. Added a middle table:

- `category_rules.match_type` now describes how this rule's **groups** combine (`all`/`any`).
- `category_rule_condition_groups`: `category_rule_id` (FK, cascade), `match_type` (`all`/`any`
  — how *this group's own conditions* combine), `position` (int, UI ordering).
- `category_rule_conditions`: now belongs to a **group** (`category_rule_condition_group_id`
  FK, cascade), not directly to the rule. Field/operator/value/value_end unchanged.

The simple, common case (no OR-of-groups needed) is just one group holding all the
conditions — collapses back to the original flat behavior, so `CategoryRuleCondition`'s own
matching logic is untouched by this change; only what it belongs to changed. One level of
nesting only (a group cannot contain another group) — not a full recursive expression tree,
per Andres's explicit choice over that heavier option.

**`category_rule_condition_groups`**:
- `category_rule_id` (FK → category_rules, cascade delete)
- `match_type` (string: `all` | `any`)
- `position` (integer)

**`category_rule_conditions`**:
- `category_rule_condition_group_id` (FK → category_rule_condition_groups, cascade delete)
- `field` (string: `name` | `merchant_name` | `amount` | `account_id` | `date`)
- `operator` (string, app-validated, not a DB enum):
  - `name`/`merchant_name`: `contains`, `equals`, `starts_with`, `regex`
  - `amount`: `equals`, `greater_than`, `less_than`, `between`
  - `account_id`: `is`
  - `date`: `before`, `after`, `between`
- `value` (string, nullable)
- `value_end` (string, nullable — `between` only)

## Models

- `App\Models\CategoryRule` — belongsTo User, belongsTo Category, hasMany CategoryRuleCondition.
  `matches(Transaction $transaction): bool`.
- `App\Models\CategoryRuleCondition` — belongsTo CategoryRule.
  `matches(Transaction $transaction): bool`, dispatches on field/operator.
- `User::categoryRules(): HasMany`.

## Actions

- `app/Actions/Models/CategoryRule/{Create,Update,Delete}CategoryRule.php`
- `app/Actions/ApplyCategoryRulesAction.php` — the engine (see full plan for exact code).
- `DeleteCategoryRule`/un-adopt flow: when `RemoveCategoryForUserAction` runs, deactivate any
  of that user's rules pointing at the removed category.

## Hook point

`app/Actions/UpdateAccountTransactionsAction.php`, right after `$transaction->refreshType();`:
```php
if ($transaction->categories->isEmpty()) {
    ApplyCategoryRulesAction::run($transaction);
}
```

## Policy

`CategoryRulePolicy` — plain ownership (`$rule->user_id === $user->id`), like
`TransactionPolicy`, not `CategoryPolicy`'s adoption model.

## UI

Routes mirroring `categories.*`:
```
Route::name('category-rules.')->group(function () {
    Volt::route('/category-rules', 'admin.category-rules.index')->name('index');
    Volt::route('/category-rules/create', 'admin.category-rules.edit')->name('create');
    Volt::route('/category-rules/{categoryRule}/edit', 'admin.category-rules.edit')->name('edit');
});
```
New sidebar entry next to `categories.index`.

- **Index**: `<x-responsive-table>` (same component `admin/categories/index.blade.php` uses),
  priority order, active/inactive toggle, up/down priority-swap buttons (no new JS dep),
  edit/delete, "Create Rule".
- **Create/Edit**: sentence-builder — "If [ALL/ANY] of: [field][operator][value] (+Add) →
  assign category [picker]". Regex reached via operator dropdown, not a separate toggle. Live
  preview via `$wire.call('previewMatchCount', ...)` (same `.then()` pattern the category
  picker modal already uses), scoped to the user's own uncategorized transactions.

## Testing

- Unit: `CategoryRuleCondition::matches()` per field/operator, incl. sad paths (malformed
  regex, missing value, non-numeric amount).
- Unit: `CategoryRule::matches()` all/any.
- Feature: `ApplyCategoryRulesAction` (priority order, inactive skipped, already-categorized
  untouched, cross-user isolation).
- Feature: end-to-end through `PullLinkedAccountTransactionsAction`.
- Feature: CRUD pages + `CategoryRulePolicy` ownership (pattern:
  `tests/Feature/CategoryOwnershipLeakTest.php`).
- Browser: sentence-builder + live preview.

## Verification

- `composer test` (`--no-tia`) green, including the MySQL job's migration path.
- Manual in-browser check: rule creates → matching transaction auto-tagged; manual
  categorization never overwritten on re-sync; priority order picks the right rule.
