# Roadmap

A living list of what's planned and what's being considered for Insights. This isn't a committed
timeline — priorities can shift, and "under consideration" items may never get built. See
[CONTRIBUTING.md](../CONTRIBUTING.md) if you'd like to help with any of these.

## Up next

Roughly in priority order:

1. **Manual (non-Plaid) accounts + CSV/Excel import** — support accounts that aren't linked
   through Plaid at all (a cash account, an old closed account, someone else's account you want to
   track), plus a way to import their transaction history from a CSV or Excel file via a
   column-mapping UI. PDF bank-statement import is a harder, separate problem (layouts vary wildly
   bank-to-bank, and reliable extraction likely needs either OCR or an AI-assisted step) and is
   being deferred to a later pass rather than blocking this one.
2. **Autocategorize rules** — per-user rules that automatically assign a category based on
   merchant name, Plaid's own category, account, amount, or date, with AND/OR logic and an
   enable/disable toggle per rule. Complements the existing merchant-history category suggestions
   rather than replacing them.
3. **Security hardening pass** — a few concrete improvements identified while researching what
   compliance standards actually apply to a self-hosted personal-finance app at this scale (short
   answer: PCI-DSS and GLBA don't, Plaid's own developer policy does and is already substantially
   met). Includes documenting safe backup practices for `APP_KEY`, recommending host-level disk
   encryption for self-hosters, and confirming production TLS is properly enforced.
4. **REST API** — token-based external access to a user's own data. The auth model (personal
   access tokens vs. Laravel Sanctum) needs deciding as part of the design, not bolted on after.

## Under consideration

No firm commitment on these yet — feedback welcome:

- **Roles/permissions model** — a real multi-user permissions system. Related to categories
  currently being a shared, per-user-adopted vocabulary rather than fully siloed per account.
- **Tagging system** — freeform tags (e.g. "business," "recurring") independent of the category
  tree, for cross-cutting labels that don't fit a strict hierarchy.
- **Report export** — CSV/PDF export of reports; which reports and which format(s) first isn't
  decided yet.
- **Plaid webhooks instead of polling** — investigated already. Plaid's `SYNC_UPDATES_AVAILABLE`
  webhook is well-documented and would fit the app's existing sync flow, but it needs a stable
  public HTTPS endpoint — a real gap for a chunk of this app's home-server self-hosted userbase.
  If pursued, the plan would be webhooks as an opt-in fast path alongside the existing polling
  schedule (kept as a safety net), not a replacement for it. Shelved for now.
- **OAuth2 login** (Google/GitHub) as an alternative to email/password.
- **Email digest summaries.**
- **AI/ML-assisted categorization** — the groundwork (merchant-history-based category suggestions)
  already exists; a bundled local AI model was investigated and set aside for now — the RAM
  footprint of even a small local LLM is a lot to ask of the typical self-hosting setup (Pi/NAS/
  budget VPS) this app targets, and simpler heuristics likely get most of the value first.

