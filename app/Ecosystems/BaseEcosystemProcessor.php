<?php

namespace App\Ecosystems;

use App\Ecosystems\Contracts\EcosystemProcessor;
use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use App\Models\InventoryEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\VulnFinding;
use App\Services\SiblingFindingNotifierService;
use App\Services\SiblingOutdatedNotifierService;
use App\ValueObjects\EcosystemProcessResultValueObject;
use App\ValueObjects\OutdatedSummaryValueObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class BaseEcosystemProcessor implements EcosystemProcessor
{
    abstract public static function registryUrl(string $packageName): string;

    abstract protected function ecosystem(): EcosystemEnum;

    public function process(
        Project $project,
        string $payloadHash,
        array $packages,
        array $findings,
        array $advisories,
        array $privatePackages,
        array $publicPackages = [],
    ): EcosystemProcessResultValueObject {
        $newFindings = [];
        $changedLatestVersions = [];

        DB::transaction(function () use ($project, $payloadHash, $packages, $findings, $advisories, $privatePackages, $publicPackages, &$newFindings, &$changedLatestVersions): void {
            $ecosystemState = ProjectEcosystem::firstOrNew([
                'project_id' => $project->id,
                'ecosystem' => $this->ecosystem()->value,
            ]);

            $packagesChanged = $ecosystemState->current_hash !== $payloadHash;

            if ($packagesChanged) {
                $this->processPackages($project, $packages, $changedLatestVersions);
            } else {
                Log::debug('BaseEcosystemProcessor: package hash unchanged, skipping package sync', [ // @pest-mutate-ignore logging not asserted in this test suite
                    'project_uuid' => $project->uuid,
                    'ecosystem' => $this->ecosystem()->value,
                    'hash' => $payloadHash,
                ]);
            }

            // Findings/advisories are processed regardless of package_hash — a client
            // can push solely because a new advisory or outdated version appeared,
            // with the installed package list unchanged.
            $newFindings = $this->processFindings($project, $findings);
            $this->processSpecific($project, array_values($advisories), $privatePackages, $publicPackages); // @pest-mutate-ignore processSpecific() implementations only foreach/count these arrays — key structure is never observed

            if ($packagesChanged) {
                $ecosystemState->current_hash = $payloadHash;
                $ecosystemState->package_count = count($packages);
            }
            $ecosystemState->last_pushed_at = now();
            $ecosystemState->save();

            Log::debug('BaseEcosystemProcessor: transaction committed', [ // @pest-mutate-ignore logging not asserted in this test suite
                'project_uuid' => $project->uuid,
                'ecosystem' => $this->ecosystem()->value,
                'packages_changed' => $packagesChanged,
                'package_count' => count($packages),
            ]);
        });

        if ($newFindings !== []) {
            resolve(SiblingFindingNotifierService::class)->notify($project, $this->ecosystem(), $newFindings);
        }

        if ($changedLatestVersions !== []) {
            resolve(SiblingOutdatedNotifierService::class)->notify($project, $this->ecosystem(), array_values(array_unique($changedLatestVersions))); // @pest-mutate-ignore $changedLatestVersions is already deduplicated via $desiredPackages->keyBy('name') before processPackages() runs — array_unique()/array_values() are defensive, not observable
        }

        $afterOutdated = $project->outdatedPackages($this->ecosystem(), $project->wantsDeltaDigest());
        $outdatedSummary = OutdatedSummaryValueObject::fromPackages($afterOutdated);

        return new EcosystemProcessResultValueObject($this->ecosystem(), $outdatedSummary, $newFindings);
    }

    /**
     * @param  list<array{ecosystem: string, name: string, payload: array<int, mixed>}>  $advisories
     * @param  list<string>  $privatePackages
     * @param  list<string>  $publicPackages
     */
    protected function processSpecific(Project $project, array $advisories, array $privatePackages, array $publicPackages = []): void
    {
        // WP processors override this to handle private/public packages and advisories.
    }

    /**
     * @param  array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}>  $packages
     * @param  list<string>  $changedLatestVersions
     */
    protected function processPackages(Project $project, array $packages, array &$changedLatestVersions): void
    {
        $currentPackagesCollection = ProjectPackage::query()
            ->where('project_id', $project->id)
            ->with('package')
            ->whereHas('package', fn ($q) => $q->where('ecosystem', $this->ecosystem()->value))
            ->get();

        $currentPackages = $currentPackagesCollection->keyBy(function (ProjectPackage $p): string {
            $package = $p->package;
            if ($package === null) {
                throw new \RuntimeException('ProjectPackage must have a loaded Package relationship');
            }

            return $package->name;
        });

        $desiredPackages = collect($packages)->keyBy('name');
        $now = now();
        $events = [];

        foreach ($desiredPackages as $name => $data) {
            $existing = $currentPackages->get($name);

            if ($existing === null) {
                $package = $this->resolvePackage($project, $name, $data);
                $packageUpdates = $this->buildPackageUpdates($package, $data);
                $this->applyPackageUpdates($package, $packageUpdates, $project, $name, $changedLatestVersions);

                ProjectPackage::create([
                    'project_id' => $project->id,
                    'package_id' => $package->id,
                    'version' => $data['version'],
                    'is_direct' => $data['is_direct'],
                    'is_dev' => $data['is_dev'],
                    'is_active' => $data['is_active'] ?? null,
                    'is_child' => $data['is_child'] ?? null,
                    'depth' => $data['depth'] ?? null,
                ]);

                $events[] = [
                    'uuid' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'ecosystem' => $this->ecosystem()->value,
                    'package_name' => $name,
                    'action' => InventoryActionEnum::Added->value,
                    'old_version' => null,
                    'new_version' => $data['version'],
                    'occurred_at' => $now,
                ];
            } else {
                $existingPackage = $existing->package;
                if ($existingPackage !== null) {
                    $packageUpdates = $this->buildPackageUpdates($existingPackage, $data);
                    $this->applyPackageUpdates($existingPackage, $packageUpdates, $project, $name, $changedLatestVersions);
                }

                if (
                    $existing->version !== $data['version']
                    || $existing->is_direct !== $data['is_direct']
                    || $existing->is_dev !== $data['is_dev']
                    || $existing->is_active !== ($data['is_active'] ?? null)
                    || $existing->is_child !== ($data['is_child'] ?? null)
                    || $existing->depth !== ($data['depth'] ?? null)
                ) {
                    $oldVersion = $existing->version;
                    $existing->update([
                        'version' => $data['version'],
                        'is_direct' => $data['is_direct'],
                        'is_dev' => $data['is_dev'],
                        'is_active' => $data['is_active'] ?? null,
                        'is_child' => $data['is_child'] ?? null,
                        'depth' => $data['depth'] ?? null,
                    ]);

                    $events[] = [
                        'uuid' => (string) Str::uuid(),
                        'project_id' => $project->id,
                        'ecosystem' => $this->ecosystem()->value,
                        'package_name' => $name,
                        'action' => InventoryActionEnum::Updated->value,
                        'old_version' => $oldVersion,
                        'new_version' => $data['version'],
                        'occurred_at' => $now,
                    ];
                }
            }
        }

        foreach ($currentPackages as $packageName => $existing) {
            if (! $desiredPackages->has($packageName)) {
                $removedVersion = $existing->version;
                $existing->delete();

                $events[] = [
                    'uuid' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'ecosystem' => $this->ecosystem()->value,
                    'package_name' => $packageName,
                    'action' => InventoryActionEnum::Removed->value,
                    'old_version' => $removedVersion,
                    'new_version' => null,
                    'occurred_at' => $now,
                ];
            }
        }

        if ($events !== []) {
            InventoryEvent::insert($events);
        }

        Log::debug('BaseEcosystemProcessor: processPackages complete', [ // @pest-mutate-ignore logging not asserted in this test suite
            'project_uuid' => $project->uuid,
            'ecosystem' => $this->ecosystem()->value,
            'event_count' => count($events),
        ]);
    }

    /**
     * @param  array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $findings
     * @return list<array{package_name: string, package_version: string, advisory_id: string, severity: 'critical'|'high'|'low'|'medium'}>
     */
    protected function processFindings(Project $project, array $findings): array
    {
        $now = now();

        $deduplicatedFindings = [];
        foreach ($findings as $finding) {
            // Clients report packages that merely depend on a vulnerable one (npm audit's string `via`)
            // as `advisory_id: "unknown"` — not a vulnerability of their own, so it must not count as one.
            if (in_array(strtolower(trim($finding['advisory_id'])), ['', 'unknown'], true)) {
                Log::info('BaseEcosystemProcessor: dropping finding without advisory id', [ // @pest-mutate-ignore logging not asserted in this test suite
                    'project_uuid' => $project->uuid,
                    'ecosystem' => $this->ecosystem()->value,
                    'package_name' => $finding['package_name'],
                    'package_version' => $finding['package_version'],
                ]);

                continue;
            }

            $deduplicatedFindings[$finding['package_name'].':'.$finding['advisory_id']] = $finding;
        }
        $findings = array_values($deduplicatedFindings);

        $resolved = [];
        foreach ($findings as $finding) {
            $package = $this->findPackageForProject($project, $finding['package_name']);

            if ($package !== null) {
                $resolved[] = ['finding' => $finding, 'package_id' => $package->id];
            } else {
                Log::warning('BaseEcosystemProcessor: could not resolve package for finding, dropping', [ // @pest-mutate-ignore logging not asserted in this test suite
                    'project_uuid' => $project->uuid,
                    'ecosystem' => $this->ecosystem()->value,
                    'package_name' => $finding['package_name'],
                    'advisory_id' => $finding['advisory_id'],
                ]);
            }
        }

        $packageIds = array_values(array_unique(array_column($resolved, 'package_id')));

        /** @var Collection<string, VulnFinding> $existingFindings */
        $existingFindings = $packageIds !== []
            ? VulnFinding::query()
                ->whereIn('package_id', $packageIds)
                ->where('source', VulnSourceEnum::ClientAudit->value)
                ->get()
                ->keyBy(fn (VulnFinding $f): string => $f->package_id.':'.$f->package_version.':'.$f->advisory_id)
            : collect();

        $findingsToInsert = [];

        foreach ($resolved as $entry) {
            $finding = $entry['finding'];
            $packageId = $entry['package_id'];
            $key = $packageId.':'.$finding['package_version'].':'.$finding['advisory_id'];
            $existing = $existingFindings->get($key);

            if ($existing !== null) {
                $existing->update(['last_seen_at' => $now]);

                continue;
            }

            $findingsToInsert[] = [
                'uuid' => (string) Str::uuid(),
                'package_id' => $packageId,
                'package_version' => $finding['package_version'],
                'advisory_id' => $finding['advisory_id'],
                'severity' => SeverityEnum::from($finding['severity'])->value,
                'source' => VulnSourceEnum::ClientAudit->value,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ];
        }

        if ($findingsToInsert !== []) {
            DB::table('vuln_findings')->insertOrIgnore($findingsToInsert);
        }

        /** @var list<array{package_name: string, package_version: string, advisory_id: string, severity: 'critical'|'high'|'low'|'medium'}> $newFindings */
        $newFindings = $findingsToInsert !== []
            ? array_values(VulnFinding::whereIn('uuid', array_column($findingsToInsert, 'uuid'))->with('package')->get()
                ->map(fn (VulnFinding $f): array => [
                    'package_name' => $f->package_name,
                    'package_version' => $f->package_version,
                    'advisory_id' => $f->advisory_id,
                    'severity' => $f->severity->value,
                ])->values()->all())
            : [];

        Log::debug('BaseEcosystemProcessor: processFindings complete', [ // @pest-mutate-ignore logging not asserted in this test suite
            'project_uuid' => $project->uuid,
            'ecosystem' => $this->ecosystem()->value,
            'new' => count($newFindings),
        ]);

        return $newFindings;
    }

    protected function findPackageForProject(Project $project, string $name): ?Package
    {
        $global = Package::query()
            ->where('project_id', 0)
            ->where('ecosystem', $this->ecosystem()->value)
            ->where('name', $name)
            ->first();

        if ($global !== null) {
            return $global;
        }

        return Package::query()
            ->where('project_id', $project->id)
            ->where('ecosystem', $this->ecosystem()->value)
            ->where('name', $name)
            ->first();
    }

    /**
     * @param  array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}  $data
     */
    protected function resolvePackage(Project $project, string $name, array $data): Package
    {
        $package = $this->findPackageForProject($project, $name);

        if ($package !== null) {
            return $package;
        }

        return Package::firstOrCreate(
            ['project_id' => 0, 'ecosystem' => $this->ecosystem()->value, 'name' => $name],
            ['source_url' => $data['source_url'] ?? null, 'author' => $data['author'] ?? null],
        );
    }

    /**
     * Applies package metadata writes, splitting `latest_version` from `source_url`/
     * `author`. Only a `latest_version` change bumps `updated_at`; `source_url`/`author`
     * is registry-metadata drift a client can legitimately re-report every push (e.g. a
     * differently-cased author string). The delta digest does not read `updated_at`
     * (see `project_packages.notified_latest_version`).
     *
     * @param  array<string, mixed>  $updates
     * @param  list<string>  $changedLatestVersions
     */
    private function applyPackageUpdates(Package $package, array $updates, Project $project, string $name, array &$changedLatestVersions): void
    {
        if ($updates === []) {
            return;
        }

        Log::debug('BaseEcosystemProcessor: package metadata changed', [
            'project_uuid' => $project->uuid,
            'ecosystem' => $this->ecosystem()->value,
            'package_name' => $name,
            'fields' => array_keys($updates),
            'old' => array_map(fn (string $field) => $package->{$field}, array_keys($updates)),
            'new' => array_values($updates),
        ]);

        $versionUpdate = array_intersect_key($updates, ['latest_version' => true]);
        $metadataUpdate = array_diff_key($updates, ['latest_version' => true]);

        if ($metadataUpdate !== []) {
            Package::withoutTimestamps(fn () => $package->update($metadataUpdate));
        }

        if ($versionUpdate !== []) {
            $package->update($versionUpdate);
            $changedLatestVersions[] = $name;
        }
    }

    /**
     * @param  array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}  $data
     * @return array<string, mixed>
     */
    protected function buildPackageUpdates(Package $package, array $data): array
    {
        $updates = [];

        $newSourceUrl = $data['source_url'] ?? null;
        if ($newSourceUrl !== $package->source_url) {
            $updates['source_url'] = $newSourceUrl;
        }

        if (($data['available_version'] ?? null) !== null && $package->latest_version !== $data['available_version']) {
            $updates['latest_version'] = $data['available_version'];
        }

        if (($data['author'] ?? null) !== null && $package->author !== $data['author']) {
            $updates['author'] = $data['author'];
        }

        return $updates;
    }
}
