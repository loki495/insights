# State

Status: **complete, awaiting Andres's review/commit decision** (2026-09-03).

Full gate green: Pint, Rector (dry-run), Peck, PHPStan (level 6, no errors), Pest
Feature/Unit (455 passing) and Browser suites (26 passing), all via `--no-tia`. Coverage run
(`--coverage --min=95`) fails on 2 pre-existing, unrelated tests
(`tests/Unit/Plaid/StatusTest.php`, `tests/Unit/ServicesTest.php`) that call the real live
Plaid status API over the network — both pass standalone and without `--coverage`, so this
looks like Xdebug/PCOV instrumentation overhead pushing a real HTTP call past some timeout
under coverage specifically. Confirmed via `git log` these two files are pre-existing/tracked,
untouched by this plan — not something to fix here without Andres's say-so per the
never-weaken-a-test hard rule.
Nothing committed to git yet — see the untracked/modified file list before committing.

## Addition after "complete" (2026-09-03): retroactive apply from the edit page
Andres asked for the create/edit page to also show exactly which existing transactions a rule
would match, plus a button to apply it to them now (not the heavier "backfill everything"
concept deferred in PLAN.md — scoped to the one rule on screen). Added:
- `App\Actions\FindMatchingTransactionsForCategoryRuleAction` — the "which of this user's
  uncategorized transactions does this rule match" query, shared by both the live preview
  (against a throwaway in-memory rule) and the action below (against a real persisted one).
- `App\Actions\ApplyCategoryRuleRetroactivelyAction` — saves nothing itself; takes an already
  -persisted `CategoryRule` and assigns its category to every currently-matching row. No
  "first match wins across rules" concept — deliberately scoped to the one rule being edited.
- `edit.blade.php` gained `applyToExistingTransactions()`, a shared `persistRule()`/
  `validationRules()` (used by both `save()` and the new method), a capped (25) matching-
  transaction list in the view, and an "Apply to N existing transactions" button gated behind
  `wire:confirm` (browser-tested with the `window.confirm = () => true` stub pattern already
  established in `BulkActionsTest.php`).
- Tests: 4 new cases in `ApplyCategoryRuleRetroactivelyActionTest.php` (ownership/cross-user
  isolation included), 5 new cases appended to `CategoryRuleEditTest.php`, 1 new browser test
  in `CategoryRuleBuilderTest.php`. Full gate re-run green after this addition (same 2
  pre-existing coverage-mode failures noted above, unrelated).

Two operational hiccups hit and fixed along the way, unrelated to the feature itself:
`storage/`, `bootstrap/cache/`, and `/tmp` inside `insights-app` accumulated root-owned files
from earlier bare `docker exec` calls (no `-u www-data`) at multiple points this session —
fixed each time with `docker exec insights-app chown -R www-data:www-data <path>`. If a
`tempnam()`/cache-permission error shows up again, that's almost certainly the same cause —
check `find storage bootstrap/cache /tmp -not -user www-data` before assuming a real bug.

## Done
- Plan approved (see PLAN.md).
- Migrations: `category_rules`, `category_rule_conditions` (run locally).
- Models: `CategoryRule`, `CategoryRuleCondition`, `User::categoryRules()`. Factories added.
- `CategoryRuleCondition::matches()` + 17 unit tests, all passing (`--no-tia`).
- `CategoryRule::matches()` + 3 unit tests, all passing (`--no-tia`).
- Gotcha hit twice already this plan: the project's PostToolUse Pint hook strips a newly-added
  `use` import as "unused" if it lands in an Edit before the Edit that actually uses it. Fix:
  re-add the import in a follow-up Edit once usage exists — it sticks the second time. Expect
  this on every new-file-with-new-import step below; check `grep "^use " <file>` after if
  phpstan/tests complain about an unexpected namespace.
- `Transaction::created_at` is `CarbonInterface` at runtime (actually `CarbonImmutable`), not
  `Illuminate\Support\Carbon` — type-hint accordingly in anything consuming it.

## Done (cont'd)
- `CategoryRulePolicy` (plain ownership, like TransactionPolicy).
- `ApplyCategoryRulesAction` + 5 feature tests, all passing.
- Hook added to `UpdateAccountTransactionsAction` (right after `refreshType()`, uncategorized-only
  guard). 2 new tests in `UpdateAccountTransactionsActionTest.php` covering auto-categorize-on-add
  and never-override-on-modified — both passing.
- **IMPORTANT operational note**: always run `docker exec` against `insights-app` with
  `-u www-data` (e.g. `docker exec -u www-data insights-app ...`). A bare `docker exec` (as
  earlier in this session) runs as root and can leave root-owned files in `storage/framework/`
  that later block the real web server from writing there — hit exactly this bug on
  `/settings/appearance` mid-plan (unrelated page, same container) and had to
  `chown -R www-data:www-data storage` to fix it. Don't repeat that.

## Done (cont'd)
- Actions: Create/Update/DeleteCategoryRule + 4 feature tests, all passing.
- Deactivate-on-unadopt wiring in `RemoveCategoryForUserAction` + test, passing.
- Routes + sidebar nav entry added.
- Index page (list, toggle, priority swap, delete) + 7 feature tests, all passing.
- Create/Edit page (sentence-builder, live preview) written, tests written but NOT YET RUN —
  paused here for the grouping revision below.

## MID-BUILD DESIGN CHANGE (2026-09-03) — READ PLAN.md's "Revision" note first
Andres wants one level of AND/OR grouping ("(X and Y) or Z"), not flat-only. New table
`category_rule_condition_groups` sits between `category_rules` and `category_rule_conditions`.
Nothing is committed to git yet (verified via `git status`), so migrations/models/actions/UI
below are being rewritten in place rather than layered with a new migration on top.

## Grouping rework: DONE
All of migrations/models/actions/index page/edit page + tests reworked and passing for the
one-level-of-groups design (see PLAN.md's Revision note). Notable bug caught along the way:
`public ?CategoryRule $categoryRule;` (no `= null` default) threw "must not be accessed before
initialization" the moment a real Livewire::test() interaction sequence (set/call across
multiple simulated requests) hydrated the component fresh without re-running mount() — PHP
typed properties have no implicit default. Fixed by declaring `= null` explicitly. Checked: no
other Livewire component in this app has the same bare-nullable-typed-property pattern, so this
wasn't a pre-existing widespread bug, just new code that hadn't been tested end-to-end yet.

Exact "(X and Y) or Z" scenario now has a passing test at every layer: unit
(CategoryRuleTest), feature (CategoryRuleEditTest, through the real Livewire component).

## Next up (in order)
1. Run the FULL test suite (`composer test --no-tia`, or at least full Pest) to catch any
   remaining regression from the grouping rework across the whole app, not just this feature's
   own test files.
2. Browser test for the sentence-builder UI (not started yet) — at minimum: create a rule with
   two groups through the real browser, confirm it saves and the live preview count updates.
3. Full `composer test --no-tia` gate (Rector/Pint/Peck/PHPStan too, not just Pest), commit,
   push (confirm with Andres first, per this project's own hard rule on pushes).

## Notes for a cold resume
- Full design lives in PLAN.md in this same folder (self-contained, don't need the original
  `/home/andres/.claude/plans/ethereal-drifting-goose.md` — that's just the plan-mode scratch
  copy).
- Un-adopt-deactivates-rules behavior was Andres's approved call, not a default assumption.
- No queue worker exists in this app — do not introduce one for this feature; v1 is
  deliberately new-transactions-only for that reason.
