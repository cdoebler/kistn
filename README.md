# Kistn - Software Package Inventory & Vulnerability Monitor

Push-based system for tracking resolved package trees and vulnerabilities across projects, with automatic cross-project sharing of vuln findings for packages multiple projects have in common. Clients run native tooling locally and push results to this API.

## How it works

```
Client (cron)
  ├─ GET  .../hashes                  ← all ecosystem hashes in one call
  ├─ compare each ecosystem hash locally
  ├─ if all equal → stop
  └─ if any differ → POST .../inventory   ← bundled: {"ecosystems": {"composer": {...}, "npm": {...}}}
                      POST .../files       ← optional: gzip-compressed lock files (all in one multipart)
```

Ingestion is always async (`ProcessProjectInventoryJob`). Every API call is logged. Every package change produces an `InventoryEvent`. Vuln findings are permanent records of `(package, version, advisory)` with `first_seen_at` / `last_seen_at`.

## Setup

Requires PHP 8.4, Composer and Node.js 22.

```bash
composer setup        # install deps, create .env, generate key, migrate, build assets
php artisan db:seed
composer dev          # queue worker, log tail, Vite
```

## Development

```bash
php artisan test --compact
composer ci:check     # pint + larastan + rector + tests
```

Browser tests need a Playwright Chromium: `npx playwright install chromium`.

## Related packages

| Package | Role |
|---|---|
| `kistn/php-client` | Framework-agnostic PHP 8.3+ client — collects Composer/npm packages, computes hashes, pushes via PSR-18 HTTP |
| `kistn/laravel-client` | Laravel service provider + `inventory:push` Artisan command |
| `kistn/wp-client` | WordPress plugin client — collects wp-plugin/wp-theme packages, pushes inventory directly to the API |
| `kistn/js-client` | Framework-agnostic JS/Node.js client — collects npm packages, computes hashes, pushes via fetch |

## API

All routes require `Authorization: Bearer <token>`. Tokens are Sanctum tokens owned by `Project`.

```
GET  /api/projects/{uuid}/hashes             ← all ecosystem hashes: {composer, npm, wp-plugin, wp-theme, wp-core}
POST /api/projects/{uuid}/inventory          ← body: {"ecosystems": {"composer": {packages, findings, ...}, ...}}
POST /api/projects/{uuid}/files              ← multipart: composer_lock, composer_json, package_lock, package_json, installed_json
GET  /api/projects/{uuid}/packages
GET  /api/projects/{uuid}/events
GET  /api/projects/{uuid}/findings
POST /api/projects/{uuid}/preflight/wp       ← WP plugin: advisory cache check
```

The `POST /inventory` body only needs to include ecosystems that have changed — the server diffs and stores each one independently. File upload accepts gzip-compressed lock files (`installed_json`, `composer_lock`, `composer_json`, `package_lock`, `package_json`) all in one multipart request. Server-side audit jobs (`ComposerAuditJob`, `NpmAuditJob`) run when the project's `audit_local_composer` / `audit_local_npm` settings are enabled.

### Uploaded File Handling & Sanitization

Every uploaded file is stored twice under `storage/app/private/projects/{uuid}/`:

- `org/{name}` — the **original** bytes, verbatim, kept for forensics/audit.
- `{name}` — a **sanitized working copy** at the top level. This is the only copy any server-side tool (`composer`/`npm`) reads.

`PackageFileSanitizerService` produces the working copy. Manifests (`composer.json`, `package.json`) carry lifecycle-hook keys (`scripts`) that a package manager executes on commands like `audit`/`outdated`; those keys are stripped so no client-supplied code runs server-side. Lock and installed files (`composer.lock`, `package-lock.json`, `installed.json`) hold only package data — no executable hooks — so they pass through byte-for-byte.

```
storage/app/private/projects/{uuid}/
  composer.json.gz          ← sanitized working copy (scripts stripped)
  composer.lock.gz          ← byte-identical to original
  package.json.gz           ← sanitized working copy (scripts stripped)
  package-lock.json.gz      ← byte-identical to original
  installed.json.gz         ← byte-identical to original
  org/
    composer.json.gz        ← original, untouched
    composer.lock.gz
    package.json.gz
    package-lock.json.gz
    installed.json.gz
```

**Rule for future work:** any code that shells out to a package manager against uploaded files MUST read the sanitized top-level working copy, never `org/`. Any newly ingested file type that can carry an executable hook must have its strip rule added to `PackageFileSanitizerService::MANIFEST_STRIP_KEYS`.

### WP Preflight

The WP plugin calls `POST /preflight/wp` before every push cycle. The server responds with:
- `stale` — slugs whose `AdvisorySnapshot` has expired or is missing (client must re-query WPScan)
- `advisories` — cached `AdvisorySnapshot` payloads for fresh slugs (client skips WPScan for these)
- `private` — slugs the server knows are private (client must skip WPScan entirely)

The push payload's `advisories` array carries client-fetched WPScan payloads back to the server for storage as `AdvisorySnapshot` records.

### Private Package Scoping

`packages.project_id=0` = global/public package. `project_id=real_id` = private/per-project.

The WP client classifies each slug by wordpress.org directory membership and reports it in `private_packages` or `public_packages`:

- **Private**: the server creates or updates a per-project package row with `is_private=true` and `advisory_checked_at=now()`, migrating any existing global row to per-project.
- **Public**: the server sets `is_private=false`. A slug that was a per-project private row is migrated back to the global row, `PackagePublishedEvent` fires and the project owner is notified.
- Slugs the client can't classify appear in neither list and keep their current status.

Composer and npm packages can be marked private manually on the project's packages page.

## Vulnerability sources

| Source | How |
|---|---|
| `client-audit` | Client runs `composer audit` / `npm audit` and includes findings in the push payload |
| `server-composer-audit` | Server runs `composer audit --locked` against uploaded `composer.lock` |
| `server-npm-audit` | Server runs `npm audit --json` against uploaded `package-lock.json` |
| `server-osv` / `server-wpscan` / `server-github` | Saloon connectors — **not yet implemented** |

When server-side audit results differ from client-reported findings, the project owner receives an `AuditDiscrepancyNotification` (mail + database).

## Cross-project sharing

Vuln findings are stored once per `(package, version, advisory)` — not once per project. When a project reports a finding for a public package it shares with other projects (via the global `project_id=0` package catalog row), any other project holding that same package at that exact installed version automatically sees it too, through a database join — no copy, no batch job.

When a new shared finding is written, projects holding the affected package at the matching version are notified immediately (respecting each project's own notification preference), exactly as if they had pushed it themselves. This means a project benefits from another project's audit run without ever having to run its own — saving redundant `composer audit` / `npm audit` runs and, for WordPress packages, redundant WPScan lookups (see `AdvisorySnapshot` / preflight above).

Private packages (`is_private=true`) are never shared — each has its own per-project package row, so findings against them are structurally isolated.

A finding recorded as `package_version = '*'` (some audit tools can't pin a single affected version) matches — and shares to — every project holding that package, regardless of installed version.

## Adding a New Ecosystem

Each ecosystem lives in `app/Ecosystems/{Name}/` with standardised subfolders:

| Subfolder | Suffix | Purpose |
|---|---|---|
| `Clients/` | `*Client` | HTTP clients for external advisory/registry APIs |
| `Jobs/` | `*Job` | Queued jobs |
| `Parsers/` | `*Parser` | Output parsers for CLI tools |
| `Processors/` | `*Processor` | Inventory processor (must extend `BaseEcosystemProcessor`) |

### Minimum steps

1. Add a case to `App\Enums\EcosystemEnum`. No other enum changes needed — `processorClass()` and `auditJobClass()` are auto-derived by convention.
2. Create `app/Ecosystems/{Name}/Processors/{Name}Processor.php` extending `BaseEcosystemProcessor` and implementing `ecosystem(): EcosystemEnum` and `public static function registryUrl(string $packageName): string`.
3. Write a migration widening the `ecosystem` column's `enum(...)` values — it's DB-enforced on every table that has one (`packages`, `project_ecosystems`, `inventory_events`, `advisory_caches`, `advisory_snapshots`, `notification_digest_items`, `api_interactions`), not `varchar(255)`.

### To enable server-side audit

4. Create `app/Ecosystems/{Name}/Jobs/{Name}AuditJob.php` with a `public static function shouldDispatch(Project $project, bool $force = false): bool` method. The job is auto-discovered via `EcosystemEnum::auditJobClass()` — no manual registration required.

## What's not built yet

- **Global advisory enrichment** — daily Saloon jobs (OSV.dev, WPScan, GitHub Advisory) to populate `advisory_cache` and `server-*` vuln findings
- **Staleness detection** — scheduled job to set `project_ecosystems.is_stale` and notify

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE.txt](LICENSE.txt).

## Acknowledgements

This software was built with the help of [Claude Code](https://claude.com/claude-code), Anthropic's agentic coding tool.
