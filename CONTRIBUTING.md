# Contributing

Thanks for taking a look at this project. It's a personal-finance app I actively use myself,
currently looking for early feedback — expect some rough edges and unfinished features (see
README's Status section).

## Getting set up

See the README's [Getting Started](README.md#getting-started) section for Docker/bare-metal setup
instructions. You don't need real Plaid credentials to start contributing — see
[Exploring without a Plaid account](README.md#exploring-without-a-plaid-account) for a seeded demo
dataset (`php artisan db:seed --class=DemoDataSeeder`). If you're specifically working on
Plaid-integration code and need to exercise the real Link flow, a free
[Plaid sandbox account](https://dashboard.plaid.com/signup) (fake institutions/transactions, not
real bank data) is enough — see [Linking a bank account](README.md#linking-a-bank-account) for the
sandbox-vs-production distinction. You do not need Plaid production access to contribute.

## Before opening a PR

Run the full check suite:

```bash
composer test
# or, inside Docker:
docker exec -u www-data -e HOME=/tmp insights-app composer test
```

This runs, in order: Rector (dry-run), Pint, `peck` (typo check), PHPStan, and the test suite
(`composer test:unit` for Feature + Unit with coverage, `composer test:browser` for the
Playwright-based browser tests — see below). All of it needs to pass, and CI runs the same
`composer test` command. If you're only working on backend PHP and don't want to set up the
browser-testing toolchain locally, `composer test:unit` alone covers everything except
`tests/Browser/` — just know CI will still run the browser suite against your PR. A few notes on
what "passing" means here:

- **PHPStan** runs at level 6 with a type-coverage floor (not the default 99%) — see the comments
  in `phpstan.neon.dist` for why. Raising these thresholds is welcome; lowering them isn't.
- **Pest coverage** has a `--min=70` floor for the same reason — it's today's real number, not an
  aspirational one. Adding tests that raise it is welcome.
- **Tests must never be deleted, weakened, or skipped to force a pass.** If a test fails, either
  the code or the test's expectations need to change deliberately — not the assertions being
  loosened to make red go green.

If Rector suggests a change you disagree with, say so in the PR rather than silently reverting it
— sometimes its suggestion is wrong for the context.

You can also run pieces individually:

```bash
php artisan test                 # or: docker exec -u www-data insights-app php artisan test
vendor/bin/pint                  # code style (auto-fixes)
vendor/bin/phpstan analyse       # static analysis
```

The test suite includes a small [Pest browser test](https://pestphp.com/docs/browser-testing)
suite (`tests/Browser/`, real Chromium via Playwright — used for things a server-rendered Feature
test can't see, like JS console errors) alongside the usual Feature/Unit tests. It needs two things
the rest of the suite doesn't:

- The `sockets` and `pcntl` PHP extensions (bare-metal only — already included in the Docker image
  via `docker/setup-dev-container.sh`).
- Playwright's Chromium browser: `npm install && npx playwright install --with-deps chromium`
  (also already baked into the Docker image; bare-metal needs it run once manually).

## Code style

Pint (Laravel's formatter) enforces style automatically — just run `vendor/bin/pint` and don't
hand-format. Match existing conventions in whatever file/directory you're editing (Actions vs.
Services vs. Livewire Volt components each have their own shape in this codebase) rather than
introducing a new pattern for the same kind of problem.

## Commit / PR expectations

- Keep commits focused — one logical change per commit is easier to review than a bundle of
  unrelated fixes.
- Write commit messages that explain *why*, not just *what* (the diff already shows what changed).
- Open PRs against `main`.
- Small, focused PRs get reviewed faster than large ones. If you're planning something big,
  opening an issue first to discuss the approach is a good idea.
- **Sign off your commits** (`git commit -s`, or add `Signed-off-by: Your Name <email>` to the
  message yourself). This is a [Developer Certificate of
  Origin](https://developercertificate.org/) — a lightweight statement that you wrote the change
  or otherwise have the right to submit it under this project's license. No CLA, no copyright
  assignment — you keep authorship of your own contribution.

## License

This project is licensed under [AGPL-3.0](LICENSE). By contributing, you agree your contribution
is licensed under the same terms.

## Reporting bugs

Open a GitHub issue with steps to reproduce. For security-sensitive issues (anything involving
auth, authorization, or Plaid credential/token handling), see [SECURITY.md](SECURITY.md) instead
of a public issue.
