<?php

namespace App\Ecosystems\Npm\Jobs;

use App\Ecosystems\Npm\Parsers\NpmAuditParser;
use App\Ecosystems\Npm\Parsers\NpmOutdatedParser;
use App\Enums\EcosystemEnum;
use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\VulnFinding;
use App\Services\AuditDiscrepancyService;
use App\Services\NotificationDispatcherService;
use App\Services\SiblingFindingNotifierService;
use App\Services\SiblingOutdatedNotifierService;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class NpmAuditJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
    ) {}

    public static function shouldDispatch(Project $project, bool $force = false): bool
    {
        if (! Storage::disk('local')->exists("projects/{$project->uuid}/package-lock.json.gz")) {
            return false;
        }

        if ($force) {
            return true;
        }

        if (! $project->auditLocalNpm()) {
            return false;
        }

        $eco = ProjectEcosystem::where('project_id', $project->id)
            ->where('ecosystem', EcosystemEnum::Npm->value)
            ->first();

        return ! $eco?->last_audited_at?->greaterThan(now()->subHours(24));
    }

    public function handle(NotificationDispatcherService $dispatcher, SiblingFindingNotifierService $siblingNotifier, SiblingOutdatedNotifierService $siblingOutdatedNotifier): void
    {
        Log::debug('NpmAuditJob: starting', ['project_uuid' => $this->project->uuid]); // @pest-mutate-ignore logging not asserted in this test suite

        $tempDir = sys_get_temp_dir().'/audit-npm-'.(string) Str::uuid(); // @pest-mutate-ignore mutants here run for real with cwd = repo root: a swapped concat mkdir()s <uuid>/tmp/... into the checkout
        mkdir($tempDir, 0755, true);

        $newFindings = [];
        $changedLatestVersions = [];

        try {
            $outdated = $this->runAudit($tempDir, $newFindings, $changedLatestVersions);
        } catch (\Throwable $e) {
            Log::error('NpmAuditJob: failed', [ // @pest-mutate-ignore logging not asserted in this test suite
                'project_uuid' => $this->project->uuid,
                'error' => $e->getMessage(),
            ]);

            return;
        } finally {
            // Framework helper, not a hand-rolled recursive unlink: under mutation testing a
            // mutated path concat made unlink() resolve relative to the worker's cwd and
            // deleted the repo's own package-lock.json.
            File::deleteDirectory($tempDir);
        }

        if ($newFindings !== []) {
            $siblingNotifier->notify($this->project, EcosystemEnum::Npm, $newFindings);
        }

        if ($changedLatestVersions !== []) {
            $siblingOutdatedNotifier->notify($this->project, EcosystemEnum::Npm, $changedLatestVersions);
        }

        $findingsByEcosystem = $newFindings !== [] ? [EcosystemEnum::Npm->value => $newFindings] : [];
        $outdatedByEcosystem = $outdated !== null ? [EcosystemEnum::Npm->value => $outdated] : [];

        if ($findingsByEcosystem === [] && $outdatedByEcosystem === []) {
            return;
        }

        $payload = new ProjectNotificationPayloadValueObject(
            outdatedByEcosystem: $outdatedByEcosystem,
            findingsByEcosystem: $findingsByEcosystem,
            triggeredAt: CarbonImmutable::now(),
        );

        if (! $payload->isEmpty()) {
            $dispatcher->dispatch($this->project, $payload);
        }
    }

    /**
     * @param  list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $newFindings
     * @param  list<string>  $changedLatestVersions
     */
    private function runAudit(string $tempDir, array &$newFindings, array &$changedLatestVersions): ?OutdatedSummaryValueObject
    {
        $newFindings = [];
        $changedLatestVersions = [];

        $gz = Storage::disk('local')->get("projects/{$this->project->uuid}/package-lock.json.gz");

        if ($gz === null) {
            Log::warning('NpmAuditJob: package-lock.json.gz not found', ['project_uuid' => $this->project->uuid]); // @pest-mutate-ignore logging not asserted in this test suite

            return null;
        }

        $lockContent = gzdecode($gz);

        if ($lockContent === false) {
            Log::warning('NpmAuditJob: failed to decompress package-lock.json.gz', ['project_uuid' => $this->project->uuid]); // @pest-mutate-ignore logging not asserted in this test suite

            return null;
        }

        file_put_contents($tempDir.'/package-lock.json', $lockContent);

        $packageJsonGz = Storage::disk('local')->get("projects/{$this->project->uuid}/package.json.gz");

        if ($packageJsonGz !== null) {
            file_put_contents($tempDir.'/package.json', gzdecode($packageJsonGz));
        }

        $auditProcess = new Process(['npm', 'audit', '--json'], $tempDir);
        $auditProcess->run();

        $installedVersions = $this->resolveInstalledVersions($lockContent);
        $findings = NpmAuditParser::parse($auditProcess->getOutput(), $installedVersions);
        $newFindings = $this->syncFindings($findings);
        (new AuditDiscrepancyService)->check($this->project, EcosystemEnum::Npm, VulnSourceEnum::ServerNpmAudit);

        $outdatedProcess = new Process(['npm', 'outdated', '--json'], $tempDir);
        $outdatedProcess->run();

        $this->updateLastAuditedAt();

        $parsed = NpmOutdatedParser::parse($outdatedProcess->getOutput());
        $before = $this->project->outdatedPackages(EcosystemEnum::Npm);
        $changedLatestVersions = $this->updateLatestVersions($parsed);
        $after = $this->project->outdatedPackages(EcosystemEnum::Npm);

        if ($before === $after) {
            return null;
        }

        return OutdatedSummaryValueObject::fromPackages($this->project->outdatedPackages(EcosystemEnum::Npm, $this->project->wantsDeltaDigest()));
    }

    /**
     * @return array<string, string> Package name → installed version, from package-lock.json
     */
    private function resolveInstalledVersions(string $lockContent): array
    {
        $lock = json_decode($lockContent, associative: true);

        if (! is_array($lock) || ! isset($lock['packages']) || ! is_array($lock['packages'])) {
            return [];
        }

        // npm's dependency tree can nest the same package name at multiple depths with
        // different versions (e.g. a top-level copy and a transitive copy pinned to an
        // older version). The shallowest copy is the best proxy for "the installed
        // version" a human or `npm audit` means, so it wins ties instead of whichever
        // entry happens to appear last in the lockfile.
        $resolved = [];

        foreach ($lock['packages'] as $path => $package) {
            if ($path === '' || ! is_string($path) || ! is_array($package)) {
                continue;
            }

            $version = $package['version'] ?? null;

            if (! is_string($version)) {
                continue;
            }

            $depth = substr_count($path, 'node_modules/');
            $name = preg_replace('#^.*node_modules/#', '', $path);

            if (! isset($resolved[$name]) || $depth < $resolved[$name]['depth']) {
                $resolved[$name] = ['version' => $version, 'depth' => $depth];
            }
        }

        return array_map(fn (array $entry): string => $entry['version'], $resolved);
    }

    /**
     * @param  list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $findings
     * @return list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>
     */
    private function syncFindings(array $findings): array
    {
        $now = now();

        $packageIds = Package::resolveIdsForProject($this->project, EcosystemEnum::Npm, array_column($findings, 'package_name'));

        $resolved = [];
        foreach ($findings as $finding) {
            $packageId = $packageIds[$finding['package_name']] ?? null;

            if ($packageId !== null) {
                $resolved[] = ['finding' => $finding, 'package_id' => $packageId];
            } else {
                Log::warning('NpmAuditJob: could not resolve package for finding, dropping', [ // @pest-mutate-ignore logging not asserted in this test suite
                    'project_uuid' => $this->project->uuid,
                    'ecosystem' => EcosystemEnum::Npm->value,
                    'package_name' => $finding['package_name'],
                    'advisory_id' => $finding['advisory_id'],
                ]);
            }
        }

        /** @var Collection<string, VulnFinding> $existing */
        $existing = $resolved !== []
            ? VulnFinding::query()
                ->whereIn('package_id', array_values(array_unique(array_column($resolved, 'package_id'))))
                ->where('source', VulnSourceEnum::ServerNpmAudit->value)
                ->get()
                ->keyBy(fn (VulnFinding $f): string => $f->package_id.':'.$f->package_version.':'.$f->advisory_id)
            : collect();

        $findingsToInsert = [];

        foreach ($resolved as $entry) {
            $finding = $entry['finding'];
            $packageId = $entry['package_id'];
            $key = $packageId.':'.$finding['package_version'].':'.$finding['advisory_id'];

            if ($existing->has($key)) {
                $existing->get($key)?->update(['last_seen_at' => $now]);

                continue;
            }

            $findingsToInsert[] = [
                'uuid' => (string) Str::uuid(),
                'package_id' => $packageId,
                'package_version' => $finding['package_version'],
                'advisory_id' => $finding['advisory_id'],
                'severity' => (SeverityEnum::tryFrom($finding['severity']) ?? SeverityEnum::Low)->value,
                'source' => VulnSourceEnum::ServerNpmAudit->value,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ];
        }

        if ($findingsToInsert !== []) {
            DB::table('vuln_findings')->insertOrIgnore($findingsToInsert);
        }

        /** @var list<array{package_name: string, package_version: string, advisory_id: string, severity: string}> */
        return $findingsToInsert !== []
            ? array_values(VulnFinding::whereIn('uuid', array_column($findingsToInsert, 'uuid'))->with('package')->get()
                ->map(fn (VulnFinding $f): array => [
                    'package_name' => $f->package_name,
                    'package_version' => $f->package_version,
                    'advisory_id' => $f->advisory_id,
                    'severity' => $f->severity->value,
                ])->values()->all())
            : [];
    }

    /**
     * Only writes when the value actually differs, so updated_at isn't touched every
     * run for an unchanged latest_version (same as WpOutdatedJob).
     *
     * @param  array<string, string>  $latestVersions
     * @return list<string> Names whose latest_version value actually changed
     */
    private function updateLatestVersions(array $latestVersions): array
    {
        $changed = [];

        foreach ($latestVersions as $name => $latest) {
            $affected = Package::query()
                ->where('ecosystem', EcosystemEnum::Npm->value)
                ->where('name', $name)
                ->where(function ($query) use ($latest): void {
                    $query->whereNull('latest_version')->orWhere('latest_version', '!=', $latest);
                })
                ->update(['latest_version' => $latest]);

            if ($affected > 0) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    private function updateLastAuditedAt(): void
    {
        ProjectEcosystem::where('project_id', $this->project->id)
            ->where('ecosystem', EcosystemEnum::Npm->value)
            ->update(['last_audited_at' => now()]);
    }
}
