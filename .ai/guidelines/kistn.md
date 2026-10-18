# Kistn — Software Package Inventory & Vulnerability Monitor

Push-based inventory and vulnerability tracking system. Clients run native tooling locally and push results to this API. Server ingests, diffs, stores, and enriches.

## Related Packages

| Package | Role |
|---|---|
| `kistn/php-client` | Framework-agnostic PHP client. Collects Composer/npm packages, computes hashes, pushes inventory via PSR-18 HTTP. |
| `kistn/laravel-client` | Laravel service provider + `inventory:push` Artisan command wrapping the PHP client. |
| `kistn/wp-client` | WordPress plugin client. Collects wp-plugin/wp-theme packages, pushes inventory directly to the API. |
| `kistn/js-client` | Framework-agnostic JS/Node.js client. Collects npm packages, computes hashes, pushes inventory via fetch. |

The Laravel package is the primary integration point for Laravel-based client projects. The PHP client is the low-level engine and can be used standalone (non-Laravel PHP, Composer plugins, etc.).

## Architecture

**Push model.** Clients (Composer plugin, WP plugin, direct API callers) compute a content hash of their local package list per ecosystem, GET all ecosystem hashes in one call, and POST only the ecosystems that differ. Server returns 202 and dispatches `ProcessProjectInventoryJob` job — ingestion is always async.

**Three vuln origins.** (1) Client-reported (`source=client-audit`) via the inventory push payload. (2) Server-side audit (`source=server-composer-audit` / `server-npm-audit`) — triggered when the project has `audit_local_composer` / `audit_local_npm` enabled in `Project.settings`, dispatches `ComposerAuditJob` / `NpmAuditJob` after file upload. (3) Global advisory enrichment via Saloon connectors (OSV.dev, WPScan, GitHub Advisory) — **not yet implemented**. Regardless of origin, a finding is stored once per `(package, version, advisory)` and is visible to every project sharing that package+version — see "Cross-Project Vuln Sharing" below.

**File upload.** Clients upload gzip-compressed lock files (`composer.lock.gz`, `composer.json.gz`, `package-lock.json.gz`, `package.json.gz`, `installed.json.gz`) alongside or after an inventory push. Files are stored compressed under `storage/app/private/projects/{uuid}/`. The PHP client's `TransmitMode` controls when to upload: `Always` (default), `Never`, or `OnDemand` (only when the local tool is unavailable). After a server-side audit run, `AuditDiscrepancyService` compares server findings against client-reported findings and notifies the project owner if they differ.

**File sanitization (ingest-time).** `FileController@store` writes each uploaded file twice: the untouched original to `projects/{uuid}/org/{name}`, and a sanitized **working copy** to the top level `projects/{uuid}/{name}`. `PackageFileSanitizerService` produces the working copy — it strips lifecycle-hook keys (`scripts`) from manifests (`composer.json`, `package.json`) so a package manager never executes client-supplied code during server-side `audit`/`outdated`; lock/installed files carry no hooks and pass through byte-for-byte. The strip map is `PackageFileSanitizerService::MANIFEST_STRIP_KEYS` (filename → keys). On any failure (bad gzip, non-JSON, re-encode error) it falls back to the original bytes so ingest never breaks. **Server-side tooling MUST read the top-level working copy, never `org/`.** Audit jobs already do — they read `projects/{uuid}/{name}` unchanged. When adding a new ingested file type that can carry an executable hook, add its strip rule to `MANIFEST_STRIP_KEYS`; do not sanitize inside the audit job.

## Auth Model

Sanctum tokens are owned by **`Project`** (not `User`). `Project` uses `HasApiTokens`. `auth()->user()` on API routes returns a `Project` instance. Do NOT add `HasApiTokens` to `User`.

`EnsureTokenBelongsToProject` middleware uses `PersonalAccessToken::findToken()` to verify the Bearer token's `tokenable_id` (int) matches `$project->id`. It runs after `auth:sanctum`.

## ID / UUID Scheme

Domain models (`Project`, `ProjectEcosystem`, `Package`, `InventoryEvent`, `VulnFinding`) have **both**:
- `id` — bigint auto-increment, used for all FKs and internal JOINs
- `uuid` — route key (`getRouteKeyName()` returns `'uuid'`), auto-generated via `HasUuids` with `uniqueIds(['uuid'])`

URL segments and API responses expose `uuid` only. Never expose integer `id` in URLs. `ApiInteraction` and `AdvisoryCache` are internal — bigint only.

## Database (Percona XtraDB Cluster / Galera)

Runs on a Percona MySQL (PXC/Galera) cluster. Galera replicates only InnoDB tables and requires **every table to have a primary key** — a table without one cannot be safely replicated (rows may apply in different order across nodes).

Migration rules:
- **Every `Schema::create()` must define a primary key.** `$table->id()` covers it. A pure pivot/junction table needs an explicit `$table->id()` or `$table->primary([...])` — never leave it PK-less.
- **InnoDB only.** Don't set another engine; Laravel's MySQL default (InnoDB) is correct — don't override it.
- Avoid `LOCK TABLES` and MyISAM. DDL replicates via Total Order Isolation, so keep schema changes small and transaction-friendly.

**Column sizing.** Columns backed by a strictly-cast PHP enum (verify via the model's `casts()` — never guess from the column name) are declared as native MySQL `enum(...)` matching the enum's values exactly, not `varchar(255)`: `ecosystem` (`packages`, `project_ecosystems`, `inventory_events`, `advisory_caches`, `advisory_snapshots`, `notification_digest_items`, `api_interactions`), `inventory_events.action`, `vuln_findings.severity`, `vuln_findings.source`. `project_ecosystems.current_hash` is `char(64)` — it's always a `sha256` hex digest (see `ProcessProjectInventoryJob::hash`), never anything else. `api_interactions.status` is `varchar(3)` (HTTP status code) and `api_interactions.endpoint` is `varchar(64)` (a `"METHOD /api/projects/{project}/..."` literal from `routes/api.php`). Adding a new `EcosystemEnum`/`InventoryActionEnum`/`SeverityEnum`/`VulnSourceEnum` case requires a migration to widen the matching DB `enum(...)` — it does not "just work" like free-text columns do. Client-supplied free text validated only by a `max:255` rule (`name`, `version`, `advisory_id`, `source_url`, `author`, `package_name`) stays `varchar(255)` — there's no fixed true-max to shrink to safely. `id` bigint PKs are a deliberate choice (see "ID / UUID Scheme") and are out of scope for size reduction.

## Data Models

| Model | Notes |
|---|---|
| `Project` | Owns tokens, scopes all API access. `settings` JSON column holds per-project flags (e.g. `audit_local_composer`, `audit_local_npm`). |
| `ProjectEcosystem` | Per-ecosystem state: `current_hash`, `last_checked_at`, `last_pushed_at`, `is_stale`. Created lazily on first push/hash request. |
| `Package` | Canonical package identity. Unique on `(project_id, ecosystem, name)`. `project_id=0` = global/public; `project_id=real_id` = private/per-project. `is_private` nullable bool: null=unknown, true=confirmed private, false=confirmed public. `advisory_checked_at` nullable timestamp (provider-agnostic — never encode provider name). `source_url`, `author`, `latest_version` nullable. |
| `ProjectPackage` | Per-project installation record. Pivot between `Project` and `Package`. Tracks `version`, `is_direct`, `is_dev`, `is_active`, `is_child`, `depth`, `notified_latest_version` (delta-digest state, see "Scheduler & Daily Digest"). Has `uuid` route key. |
| `InventoryEvent` | Append-only. No `updated_at`. `action` ∈ `added|updated|removed`. |
| `VulnFinding` | Append-only, package-scoped (`package_id`, not `project_id`) — a permanent record of `(package, version, advisory)`. `source` ∈ `client-audit\|server-osv\|server-wpscan\|server-github\|server-composer-audit\|server-npm-audit`. Visible to any project whose installed version matches (or when `package_version='*'`), via a join through `project_packages`/`packages` — no per-project copy. New shared findings actively notify sibling projects holding the affected package+version, respecting each project's own notification preference. |
| `AdvisoryCache` | Global version-level TTL cache keyed on `(ecosystem, name, version)`. No timestamps. |
| `AdvisorySnapshot` | Client-reported WPScan payloads keyed on `(ecosystem, name)`. Used by preflight to detect stale slugs. Fields: `advisories`, `checked_at`, `expires_at`. |
| `ApiInteraction` | Append-only call log. Every API request writes one row. |

Ecosystems: `composer`, `npm`, `wp-core`, `wp-plugin`, `wp-theme` (`App\Enums\EcosystemEnum`).

## API Routes (`routes/api.php`)

All routes require `auth:sanctum` + `EnsureTokenBelongsToProject`. `{project}` binds via `uuid`.

```
GET  /api/projects/{project}/hashes       → InventoryController@hashes          (all ecosystems)
POST /api/projects/{project}/inventory    → InventoryController@store  (202)     (bundled ecosystems)
POST /api/projects/{project}/files        → FileController@store       (202)     (all lock files, one multipart)
GET  /api/projects/{project}/packages     → PackageController@index
GET  /api/projects/{project}/events       → EventController@index
GET  /api/projects/{project}/findings     → FindingController@index
POST /api/projects/{project}/preflight/wp → PreflightController@wp
```

## Ingestion Flow

`InventoryController@store` iterates each ecosystem in `request.ecosystems` → computes `sha256` of sorted packages → compares to `ProjectEcosystem.current_hash`. Dispatches `ProcessProjectInventoryJob` per ecosystem that differs → 202.

`ProcessProjectInventoryJob` (queued, per ecosystem):
1. Wraps everything in a DB transaction.
2. Diffs desired vs current `packages`.
3. Inserts `inventory_events` rows — **must supply `uuid` explicitly** since `insert()` bypasses model events. Use `(string) Str::uuid()`.
4. Upserts/deletes `packages` rows.
5. Upserts/resolves `vuln_findings` for `source=client-audit`.
6. Updates `ProjectEcosystem`: `current_hash`, `last_pushed_at`, `package_count`.

## Public / Private Package Scoping

The authoritative WP public/private signal is **wordpress.org directory membership**, determined client-side from WP core's own update transient (never from WPScan presence or a `PluginURI`/`ThemeURI` header — a public plugin can lack both). Each push carries two slug lists per WP ecosystem:

- `private_packages` — slugs the client confirmed absent from the wordpress.org directory. The server:
  1. Ensures a per-project `Package` row (`project_id=real_id`) exists for each private slug.
  2. Sets `is_private=true` + `advisory_checked_at=now()` on that row.
  3. If a global (`project_id=0`) row exists, migrates it to per-project and points all `project_packages` there.
- `public_packages` — slugs the client confirmed present in the directory. The server confirms `is_private=false` + `advisory_checked_at=now()`; if the slug was a per-project private row, it migrates that row **back** to the shared global row (repointing `project_packages`) and fires `PackagePublishedEvent`. A mislabelled public plugin therefore self-heals on its next push.

Slugs the client can't classify (`in_directory=null`, no update check yet) appear in neither list and leave `is_private` untouched.

Both lists are handled by `App\Ecosystems\Wp\AbstractWpProcessor` (`processPrivatePackages` / `processPublicPackages`), which `WpPluginProcessor` and `WpThemeProcessor` extend. A WPScan advisory snapshot arriving for a plugin's own slug also confirms it public via the same path. The actual global↔per-project migration (repointing `project_packages`, deleting the orphaned row, refreshing `source_url`/`author`/`latest_version`, firing `PackagePublishedEvent`) lives in **`App\Services\PackagePrivacyService::markPrivate`/`markPublic`** — it's ecosystem-agnostic, not WP-specific. `AbstractWpProcessor` calls it for the push-driven WP flow above; the manual "Mark as private" / "Reset registry status" actions on the packages page (`resources/views/pages/projects/⚡packages.blade.php`) call the same service directly for **any** ecosystem, including composer/npm — those ecosystems have no client-payload equivalent of `private_packages`/`public_packages` (nothing in the push pipeline sets `is_private` for them), so the manual UI action is the only way a composer/npm package becomes private. Pushing a new `source_url` does not change privacy status.

**Column naming rule:** Use provider-agnostic names for any column tracking advisory/enrichment state. `advisory_checked_at` not `wpscan_checked_at`. Never encode a specific provider name in a database column or model property.

## Cross-Project Vuln Sharing

`VulnFinding` keys off `package_id` (not `project_id`) — the same public/private split `Package` already has (`project_id=0` global vs per-project private row) gives sharing and isolation for free:

- Public package finding → `package_id` points at the global row → visible to every project with a matching `ProjectPackage.version` (or when `package_version='*'`).
- Private package finding → `package_id` points at that project's own private row → structurally invisible to anyone else.

`Project::vulnFindings()` is a query-builder join (`project_packages` ⋈ `vuln_findings` on `package_id`, version-matched), not an Eloquent `hasMany` — findings have no `project_id` column at all.

Package name → id resolution always prefers the global (`project_id=0`) row over a private per-project row when both exist: `ComposerAuditJob::syncFindings` and `NpmAuditJob::syncFindings` call the shared `Package::resolveIdsForProject()`; `BaseEcosystemProcessor::processFindings` (client-reported findings) applies the same global-first rule per-finding via `findPackageForProject()`. Either path writes `vuln_findings` keyed on `package_id`. When a genuinely new `(package_id, package_version, advisory_id)` row is inserted, `SiblingFindingNotifierService::notify()` runs — batched across the whole finding set, not per-finding: one `whereIn('name', ...)` to resolve the affected packages' global rows, one `whereIn('package_id', ...)` against `project_packages` to find every sibling holding one of them, then an in-memory group-by to match each finding's `package_version` against each sibling's installed version before dispatching `NotificationDispatcherService` once per affected sibling project.

Findings have no `resolved_at` — a finding is a permanent fact about a package version, not something a project's audit run can "resolve": "this project upgraded" and "this vulnerability was patched" are different things once findings are shared. Exposure-window reporting is not built yet; it is derivable from `InventoryEvent` history cross-referenced against `first_seen_at`.

## Scheduler & Daily Digest

`Schedule::command('notifications:send-digest')->everyMinute()` (`routes/console.php`) and whatever drives `schedule:run` in deployment **must** run every minute, not less often. `User.settings['digest_time']` + `User.settings['timezone']` are per-user and can point at any minute of the day in any offset — a coarser cadence (hourly, every-15-minutes) would silently miss users whose configured send time falls between ticks. Don't "optimize" this cadence down.

`SendDigestNotificationsCommand::processUser()` claims the per-user idempotency guard (`User.last_digest_sent_at`) **before** calling `notify()`/deleting the sent `NotificationDigestItem` rows, not after. With the guard written last, a crash between "mail sent" and "guard persisted" (a transient SMTP failure, a Galera/PXC deadlock on the item delete, the scheduler process being killed mid-send) leaves the user still looking "due" on the next tick, and the same digest goes out again — every few minutes instead of once a day. Claiming the guard first flips the failure mode from "duplicate mail" to "one digest silently rolls into tomorrow's run" (its items stay in `notification_digest_items` and get swept up next time), which is the correct trade-off. Any change to this method must preserve that ordering — never write the guard after the send.

**Delta digest content ("Changes only").** An outdated package is reported when `project_packages.notified_latest_version` is NULL or differs from `packages.latest_version`; `NotificationDispatcherService` writes that column for every outdated package it sends or queues as a digest item. All outdated lookups go through `Project::outdatedPackages($ecosystem, $onlyUnnotified)` (processor, `FinalizeProjectWpNotificationJob`, composer/npm audit jobs) — don't reintroduce a per-caller copy. Never use `packages.updated_at` (or any timestamp on `packages`) as a "newly outdated" signal: the row is shared across projects and rewritten by pushes, `WpOutdatedJob`, audit jobs and `PackagePrivacyService`, so any such timestamp reports the same package in every daily digest.

## Inventory Push Payload

Bundled: one request covers all changed ecosystems.

```json
{
  "ecosystems": {
    "composer": {
      "packages": [
        {
          "name": "vendor/pkg",
          "version": "1.0.0",
          "is_direct": true,
          "is_dev": false,
          "is_active": null,
          "is_child": null,
          "depth": 0,
          "source_url": null,
          "available_version": "2.0.0",
          "author": "Author Name"
        }
      ],
      "findings": [
        { "package_name": "vendor/pkg", "package_version": "1.0.0", "advisory_id": "GHSA-...", "severity": "high" }
      ],
      "private_packages": []
    },
    "wp-plugin": {
      "packages": [...],
      "findings": [],
      "private_packages": ["my-private-plugin"],
      "public_packages": ["contact-form-7"],
      "advisories": [
        { "ecosystem": "wp-plugin", "name": "my-plugin", "payload": { "vulnerabilities": [] } }
      ]
    }
  }
}
```

Only include ecosystems that have changed. All package fields except `name`, `version`, `is_direct` are optional/nullable. `private_packages` / `public_packages` (WP only) are the client's wordpress.org-directory classification — see "Public / Private Package Scoping". `advisories` (WP only) carries client-fetched WPScan payloads — server upserts them into `AdvisorySnapshot`.

Validated by `StoreInventoryRequest`. Use typed accessors `$request->ecosystems()` — not `$request->validated()['ecosystems']` (returns `mixed`).

## Accessibility

The app ships light and dark color schemes (Flux's `.dark` class toggle, see `resources/css/app.css`). Every new view must work in both — check contrast in both, not just the one you're looking at while developing.

**Muted/secondary text color.** `--color-zinc-50…950` in `resources/css/app.css` are custom values, not stock Tailwind — `zinc-400` and `zinc-500` do **not** carry acceptable contrast in both schemes on their own (`zinc-500` fails WCAG AA on dark backgrounds, `zinc-400` fails on light backgrounds). Never use `text-zinc-400` or `text-zinc-500` alone. Always pair: `text-zinc-500 dark:text-zinc-400`. This is the established pattern across the app (e.g. `resources/views/pages/settings/⚡security.blade.php`) — match it for any new muted/secondary text, captions, or table placeholder text (`—`).

**Landmarks.** Every page must resolve to exactly one `<main>` (Flux's `<flux:main>` renders one — see `layouts/app.blade.php`; plain layouts like `layouts/auth/simple.blade.php` wrap their content in a literal `<main>`). The authenticated shell (`layouts/app/sidebar.blade.php`) has a "Skip to content" link targeting `#main-content` as the first focusable element in `<body>` — keep it there if you touch that layout, and give any new `<main>`-bearing layout the same treatment if it grows nav/header content worth skipping past.

**Accessible names.** Icon-only `<flux:button icon="...">` (no visible text) needs an explicit `aria-label` — don't rely on the icon name being self-explanatory to a screen reader. Any `readonly`/copy-target `<flux:input>` or raw `<input>` needs `aria-label` or a linked `<flux:label>` even if a visually-adjacent label exists but isn't in a `<flux:field>` wrapper (Flux only auto-wires `for`/`id` inside `<flux:field>`). An input styled only with a `:placeholder` (no visible label) needs `label="..." label:sr-only` instead — placeholder text disappears on focus and isn't a substitute for a label. A `<flux:select>`/`<flux:input>` associated with separate visible label text (not a `<flux:label>` prop) needs `aria-labelledby` pointing at that text's `id`.

**Interactive elements need native semantics.** Use `<button type="button">`, never a `<span>`/`<div>` with `@click` — a span has no keyboard focus or `Enter`/`Space` activation and Tailwind's preflight already resets button chrome, so a button can be styled to look identical. A custom tab-switcher (buttons that filter content, not real `<a>` navigation) needs `role="tablist"` on the container and `role="tab"` + `aria-selected` on each button — there's no native HTML tab element. State changes not reflected in visible text changing near a focused control (e.g. a "Copy" button whose label flips to "Copied!") should carry `aria-live="polite"` so assistive tech announces the change.

**Color is never the only signal.** Pair color with text, an icon, or (for compact contexts) a visually-hidden `<span class="sr-only">` describing the state — see `version_status` rendering in `resources/views/pages/projects/⚡packages.blade.php` for the pattern (colored arrow glyph + `title` tooltip + `sr-only` text fallback for non-mouse users).

**How light/dark actually switches.** Two separate Flux directives are involved, both from `partials/head.blade.php` / each layout's `</body>` — don't confuse them:

- `@fluxAppearance` (in `<head>`) — inline, dependency-free `<script>` that runs once at parse time. Reads `localStorage['flux.appearance']` (`'light'`/`'dark'`/absent → falls back to `prefers-color-scheme`) and toggles `.dark` on `<html>` before first paint, avoiding a flash of the wrong theme.
- `@fluxScripts` (right before `</body>`) — loads Flux's actual JS bundle (Alpine + Livewire client runtime + Flux's reactive appearance store). This is what makes the scheme switch **live**: it registers the `prefers-color-scheme` `change` listener and re-applies appearance on Livewire's `livewire:navigated` event. Without it, a page only ever gets the one-time correction from `@fluxAppearance` and never updates again until reloaded.

**Every full HTML document layout needs all three of:** `class="dark"` hardcoded on `<html>` (the correct pre-JS/no-JS fallback — matches the rest of the app so there's no jarring light/dark mismatch before the script runs), `@fluxAppearance` in `<head>`, and `@fluxScripts` before `</body>`. `welcome.blade.php` is the one non-Livewire full-page view in the app (own `<html>`, not part of the authenticated SPA); without `@fluxScripts` and the hardcoded `dark` fallback it looks "stuck" and doesn't react live to scheme changes. Any new standalone full-page view (i.e. anything that isn't rendered inside `layouts/app.blade.php` or `layouts/auth.blade.php`) needs the same three pieces, even if it has zero Livewire components — `@fluxScripts` costs one cached static JS file and stays idle with no `wire:`-driven elements on the page, it doesn't hit the backend.

## Localization

The app ships English (default) and German. `App\Enums\LocaleEnum` (`en`, `de`) is the single source of truth for supported locales — the select field on `resources/views/pages/settings/⚡profile.blade.php` and `SetLocaleMiddleware`'s negotiation both read `LocaleEnum::cases()`.

**Locale resolution.** `App\Http\Middleware\SetLocaleMiddleware`, registered on the `web` group in `bootstrap/app.php`: an authenticated user's `User.settings['locale']` override (`User::localeOverride(): ?LocaleEnum`) wins if set; otherwise the locale is negotiated from the `Accept-Language` header via `Request::getPreferredLanguage()`, falling back to `config('app.locale')`. This runs fresh on every request — guests and users without an override always follow their browser's language.

**Translation storage.** Every user-facing string in the app is wrapped in `__('English sentence')`, using the literal English string as the translation key (Laravel JSON translations). German strings live in `lang/de.json`. A missing key falls back to the English source string automatically — safe to add `__()` calls before their `lang/de.json` entry exists. Framework-level messages (Fortify auth errors, validation messages) are mirrored in `lang/de/{auth,passwords,validation}.php` from the `lang/en/*.php` originals. A fixed-domain enum label rendered in a view (severity, vuln source, version status) gets a `label()` method on the enum itself that calls `__()` internally — see `SeverityEnum::label()`, `VulnSourceEnum::label()`, `VersionStatusEnum::label()` — never inline a `ucfirst()`/`str_replace()` translation hack in a Blade file.

**Async mail.** `User implements Illuminate\Contracts\Translation\HasLocalePreference` (`preferredLocale(): string`, override ?? `config('app.locale')`). Laravel's notification pipeline calls this automatically when queuing a notification, so mail/notification code never needs to call `App::setLocale()` manually.

**Adding a new user-facing string:** wrap it in `__()`, add the German translation to `lang/de.json` (or the relevant `lang/de/*.php` for a new Fortify/validation key) in the same PR — mirrors the same-PR expectation `CONTRIBUTING.md`'s "Coding standards" section already sets for accessibility.

## Testing

Tests use real Sanctum tokens — `$project->createToken('test')->plainTextToken` — not `Sanctum::actingAs()`. API URLs use `$project->uuid`, never `$project->id`.

Browser/e2e tests (`tests/Browser/`, Pest browser plugin) leak a `playwright run-server` + headless Chromium process per invocation that the plugin never tears down. `composer test` and `composer mutate` both wrap the actual run with a `test:cleanup` step (`pkill` on `playwright run-server` / `headless_shell`) before and after, so repeated local runs don't accumulate processes and OOM the container. If you ever call `php artisan test` directly (bypassing composer), run `composer run test:cleanup` afterwards.

**Flaky tests must always be fixed, never ignored.** If a test fails once and passes on retry with no code change, that is not acceptable noise — root-cause it (race condition, shared mutable state like global `PATH`/env vars across parallel workers, unseeded randomness, time-dependent assertions, external network/service dependency) and fix it in the same pass you find it. Do not wave it off as "pre-existing" and move on without at least filing the fix; a flaky test that stays flaky erodes trust in every subsequent green run.

`ComposerAuditJobTest`'s `'ComposerAuditJob handle detects a real vulnerability and sends a notification'` test is tagged `->group('network')` — it shells out to the real `composer audit` against Packagist's live advisory API, pinned to `guzzlehttp/guzzle 6.3.0`. This is inherently non-deterministic (network availability, rate limits, advisory DB changes). `composer test` / `composer test:fast` / `scripts/mutate.sh` all pass `--exclude-group=network` so it never runs in the default gates. Run it explicitly with `composer test:network` when verifying real Packagist integration; don't remove `--exclude-group=network` to "fix" a failure there — the sibling test `'ComposerAuditJob handle detects a vulnerability offline via a fake composer binary and sends a notification'` covers the same logic deterministically via a faked `composer` binary and is what default CI relies on.

### Quality gates

Three tiers, run via `composer <script>`:

- **`ci:check-fast`** — lint, Rector, PHPStan, test suite minus the Browser testsuite. No e2e, no mutation. Quickest. Use for rapid iteration on backend-only implementations (no view/UI surface touched).
- **`ci:check`** — lint, Rector, PHPStan, full test suite (including browser tests). Must pass after **every task**.
- **`ci:check-full`** — `ci:check` plus `composer mutate` (Pest mutation testing, `--covered-only`, Browser testsuite excluded — mutation testing gains nothing from re-running e2e flows per mutant, and doing so risks the process-leak above under mutate's much higher invocation count). Slower. Must pass after a **major task, a task set, or when a plan finishes** — not after every small task.

`mutate` is scoped to `--class="App\Ecosystems,App\Services,App\ValueObjects,App\Models"` — the core domain logic, not the whole app. A blind whole-app `--mutate` run fails outright: Pest requires every test file to declare `covers()`/`mutates()` before it can map coverage to mutants at that scale, and this codebase doesn't use that annotation. Widening the scope means either retrofitting `covers()` across the test suite or accepting `--everything` (mutates uncovered code too, mostly noise). Extend the `--class` list incrementally instead as new domain namespaces need coverage.

Mutants execute for real inside test workers whose cwd is the repo root. Code in the mutation scope must not build filesystem paths it then deletes: a `ConcatRemoveLeft` mutant on a hand-rolled recursive `unlink($dir.'/'.$item)` resolves to a bare relative filename and deletes files in the repo itself (e.g. `package-lock.json`). Use `File::deleteDirectory()` / `Storage` for cleanup instead of custom recursive deletes, and mark lines that build a filesystem path from concatenation `@pest-mutate-ignore` (a `ConcatSwitchSides` mutant on a temp-dir path creates stray `<uuid>/tmp` dirs in the repo root).

`scripts/mutate.sh` enforces `MIN_SCORE` (74%; repeated runs produce identical survivor sets) via `pest --mutate --min`. It's a ratchet: raise it as the score improves, never lower it to make a change pass — kill the new surviving mutants or `@pest-mutate-ignore` genuinely unobservable lines (logging) instead. The script calls `vendor/bin/pest` directly on purpose: `php artisan test` treats `--min` as its own coverage option and silently drops it (and `--ansi` is an artisan-only flag paratest rejects). A score swing of more than a few tenths with no source change is a signal, not noise — typical causes are a test using a fixed temp path (turns every later mutant in the file into a false kill) or a genuinely uncaught mutant (missing assertion) occasionally false-killed by environment. If a mutant flips between runs, isolate it with `vendor/bin/pest --mutate <same options> --id=<ID>` — a consistent result in isolation means a real gap to close with an assertion.

`pest --mutate` is flaky in this environment: an identical invocation sometimes reports `0 Mutations for 0 Files created` instead of the real ~1300 for this scope — a coverage-collection race in the tool, not a code signal. `composer mutate` runs `scripts/mutate.sh`, which detects that exact string and retries (3 attempts) before failing the build. Never gate CI on a raw `pest --mutate` exit code directly — with no retry, the same commit can go green or red with no code change. If the flake changes shape (different message, different false-positive pattern), update the detection string in `scripts/mutate.sh` rather than removing the retry wrapper.

All `app/` folders enforce a **folder-name = class-suffix** rule:

| Folder | Required suffix | Example |
|---|---|---|
| `app/Enums/` | `*Enum` | `EcosystemEnum` |
| `app/Jobs/` | `*Job` | `ProcessProjectInventoryJob` |
| `app/Services/` | `*Service` | `AuditDiscrepancyService` |
| `app/ValueObjects/` | `*ValueObject` | `OutdatedSummaryValueObject` |

Inside `app/Ecosystems/{Name}/`, the same rule applies per subfolder:

| Subfolder | Required suffix |
|---|---|
| `Clients/` | `*Client` |
| `Jobs/` | `*Job` |
| `Parsers/` | `*Parser` |
| `Processors/` | `*Processor` |

`app/Models/`, `app/Http/`, `app/Events/`, `app/Listeners/`, `app/Notifications/` use their own established suffixes and are not affected by this rule.

### Ecosystem structure

Every ecosystem lives in `app/Ecosystems/{Name}/` with the subfolders above.

`EcosystemEnum::processorClass()` derives the processor FQCN by pure convention — `App\Ecosystems\{CaseName}\Processors\{CaseName}Processor`. No match block — adding a new ecosystem requires no enum changes beyond the case declaration.

`EcosystemEnum::registryUrl()` delegates to the processor's `public static function registryUrl(string $packageName): string`. Implement it in the processor — no match block in the enum.

`EcosystemEnum::auditJobClass()` auto-discovers `{CaseName}AuditJob` via `class_exists()`. Creating the file is the only registration step — no manual wiring needed.

`app/Ecosystems/Wp/` is a shared namespace for utilities shared across WP ecosystems (e.g., `WpOrgClient`, `WpOutdatedJob`). Each WP ecosystem has its own `Processors/` folder — there is no shared `Wp/Processors/`.
