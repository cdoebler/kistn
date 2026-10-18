# Contributing to Kistn

Push-based inventory and vulnerability tracking system. Server ingests, diffs, stores, and enriches package/vuln data reported by native clients (`kistn/php-client`, `kistn/laravel-client`, `kistn/wp-client`, `kistn/js-client`).

## Prerequisites

PHP 8.4, Composer, Node.js 22.

## Setup

```bash
composer setup
php artisan db:seed
```

### AI coding agents

Project guidelines for agents live in [`.ai/guidelines/kistn.md`](.ai/guidelines/kistn.md). Run `php artisan boost:install` to generate the files for your agent (`CLAUDE.md`, `AGENTS.md`, MCP config, skills); Laravel Boost merges the project guidelines in. Generated files are gitignored — edit `.ai/guidelines/` instead, then run `php artisan boost:update`.

## Development workflow

1. Branch off `main`.
2. Make your change.
3. Run the matching quality gate (below) — all checks must pass before you open a PR.
4. Open a PR against `main` with a short description of the change and why.

Coverage must never decrease. Every change needs a test (new or updated) — see [Testing](#testing).

## Architecture — read before touching ingestion or auth code

Full architecture, data model, and API reference live in [`.ai/guidelines/kistn.md`](.ai/guidelines/kistn.md) and [`README.md`](README.md). Key constraints that are easy to violate by accident:

- **Auth model**: Sanctum tokens are owned by `Project`, not `User`. `auth()->user()` on API routes returns a `Project`. Never add `HasApiTokens` to `User`.
- **UUID scheme**: domain models expose `uuid` in URLs/API responses, never the integer `id`. `id` stays internal for FKs/joins.
- **Database is Percona XtraDB Cluster (Galera)**: every `Schema::create()` must define a primary key (`$table->id()` or an explicit `$table->primary([...])` for pivot tables) — a table without one can't replicate safely. InnoDB only; don't override the engine.
- **Column sizing**: columns backed by a strictly-cast PHP enum must be a native MySQL `enum(...)` matching the enum's values, not `varchar(255)`. Adding an enum case requires a migration widening the DB `enum(...)` too — it doesn't "just work."
- **File sanitization**: server-side tooling (audit jobs, anything that shells out to `composer`/`npm`) must read the sanitized top-level working copy (`projects/{uuid}/{name}`), never the original under `org/`. Adding a new ingested manifest type that can carry a lifecycle hook means adding a strip rule to `PackageFileSanitizerService::MANIFEST_STRIP_KEYS` — don't sanitize inside the audit job itself.
- **Vuln findings are package-scoped, not project-scoped** (`VulnFinding.package_id`, no `project_id` column). Sharing/isolation between projects falls out of the existing public (`project_id=0`) vs. private (`project_id=real_id`) split on `Package` — see "Cross-Project Vuln Sharing" in [`.ai/guidelines/kistn.md`](.ai/guidelines/kistn.md) before changing finding resolution logic.
- **Provider-agnostic column naming**: any column tracking advisory/enrichment state must stay provider-agnostic (`advisory_checked_at`, not `wpscan_checked_at`). Never encode a specific provider name in a DB column or model property.

## Project structure conventions

`app/` enforces folder-name = class-suffix:

| Folder | Required suffix | Example |
|---|---|---|
| `app/Enums/` | `*Enum` | `EcosystemEnum` |
| `app/Jobs/` | `*Job` | `ProcessProjectInventoryJob` |
| `app/Services/` | `*Service` | `AuditDiscrepancyService` |
| `app/ValueObjects/` | `*ValueObject` | `OutdatedSummaryValueObject` |

Inside `app/Ecosystems/{Name}/`, the same rule applies per subfolder: `Clients/*Client`, `Jobs/*Job`, `Parsers/*Parser`, `Processors/*Processor`.

Adding a new ecosystem (e.g. for a new package manager) requires no `EcosystemEnum` match-block changes beyond the case itself — `processorClass()` and `auditJobClass()` are derived by naming convention. See "Adding a New Ecosystem" in `README.md` for the minimum steps.

## Coding standards

- PHP 8.4, strict types and return declarations on all methods.
- Constructor property promotion for DI.
- Follow existing conventions in sibling files before introducing a new pattern — check how similar code is already structured in this codebase.
- No speculative abstractions: don't add config, interfaces, or flexibility for hypothetical future needs. Match the scope of the change to what's asked.
- Comments explain *why*, not *what* — skip comments that just restate the code.
- Every new view must work in both light and dark color schemes; see the Accessibility section of [`.ai/guidelines/kistn.md`](.ai/guidelines/kistn.md) for the muted-text-color, landmark, and accessible-name rules enforced across the app.
- Any new user-facing string in an authenticated view, an auth form, or a mail template must be wrapped in `__()` and shipped with its German translation (`lang/de.json`, or a `lang/de/*.php` key for a new framework-level message) in the same PR — see the Localization section of [`.ai/guidelines/kistn.md`](.ai/guidelines/kistn.md).

Run Pint after any PHP change:

```bash
vendor/bin/pint --dirty
```

## Testing

Tests use real Sanctum tokens (`$project->createToken('test')->plainTextToken`), not `Sanctum::actingAs()`. API URLs use `$project->uuid`, never `$project->id`.

```bash
php artisan test --compact
php artisan test --compact --filter=testName
```

Flaky tests must be fixed, never ignored or skipped — root-cause races, shared mutable state, unseeded randomness, or external dependencies in the same pass you find them.

`composer test` leaks a `playwright run-server` + headless Chromium process per invocation; it wraps runs with a cleanup step automatically. If you call `php artisan test` directly, run `composer run test:cleanup` afterwards.

## Quality gates

All run via `composer <script>`:

| Script | What it runs | When |
|---|---|---|
| `ci:check-fast` | lint, Rector, PHPStan, tests minus Browser suite | rapid iteration on backend-only changes |
| `ci:check` | lint, Rector, PHPStan, full test suite incl. browser tests | **must pass after every task** |
| `ci:check-full` | `ci:check` + Pest mutation testing (`--covered-only`, scoped to `App\Ecosystems`, `App\Services`, `App\ValueObjects`, `App\Models`) | after a major task, task set, or when a plan finishes |

`composer test` excludes the `network` group (tests hitting live third-party APIs, e.g. Packagist). Run `composer test:network` explicitly to verify real integrations — don't remove the exclusion to "fix" a network-dependent failure.

## Commits

- Keep commit messages short and precise, focused on the change.

## Pull requests

- Describe what changed and why, not just what.
- Link the related issue/ticket if one exists.
- Confirm the appropriate quality gate (`ci:check` at minimum) passed before requesting review.
