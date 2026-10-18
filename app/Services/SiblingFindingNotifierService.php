<?php

namespace App\Services;

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SiblingFindingNotifierService
{
    public function __construct(
        private readonly NotificationDispatcherService $dispatcher,
    ) {}

    /**
     * @param  list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $newFindings
     */
    public function notify(Project $actingProject, EcosystemEnum $ecosystem, array $newFindings): void
    {
        if ($newFindings === []) {
            return;
        }

        $packageNames = collect($newFindings)->pluck('package_name')->unique()->all(); // @pest-mutate-ignore whereIn() below is correct with or without duplicate values

        /** @var Collection<string, Package> $packagesByName */
        $packagesByName = Package::query()
            ->where('project_id', 0)
            ->where('ecosystem', $ecosystem->value)
            ->whereIn('name', $packageNames)
            ->get()
            ->keyBy('name');

        $packageIds = $packagesByName->pluck('id')->all();

        /** @var Collection<int, ProjectPackage> $projectPackages */
        $projectPackages = $packageIds === []
            ? collect()
            : ProjectPackage::query()
                ->whereIn('package_id', $packageIds)
                ->where('project_id', '!=', $actingProject->id)
                ->get(['project_id', 'package_id', 'version']);

        /** @var array<int, list<array{project_id: int, version: string}>> $projectPackagesByPackageId */
        $projectPackagesByPackageId = $projectPackages
            ->groupBy('package_id')
            ->map(fn ($group) => $group->map(fn (ProjectPackage $p): array => [
                'project_id' => $p->project_id,
                'version' => $p->version,
            ])->all())
            ->all();

        /** @var array<int, list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>> $findingsBySibling */
        $findingsBySibling = [];

        foreach ($newFindings as $finding) {
            $package = $packagesByName->get($finding['package_name']);

            if ($package === null) {
                continue; // private package — no siblings possible
            }

            $candidates = $projectPackagesByPackageId[$package->id] ?? [];

            foreach ($candidates as $candidate) {
                if ($finding['package_version'] !== '*' && $candidate['version'] !== $finding['package_version']) {
                    continue;
                }

                $findingsBySibling[$candidate['project_id']][] = $finding;
            }
        }

        foreach ($findingsBySibling as $siblingProjectId => $findings) {
            $sibling = Project::find($siblingProjectId);

            if ($sibling === null) {
                continue;
            }

            $payload = new ProjectNotificationPayloadValueObject(
                outdatedByEcosystem: [],
                findingsByEcosystem: [$ecosystem->value => $findings],
                triggeredAt: CarbonImmutable::now(),
            );

            $this->dispatcher->dispatch($sibling, $payload);

            Log::debug('SiblingFindingNotifier: notified sibling project of shared finding', [
                'acting_project_uuid' => $actingProject->uuid,
                'sibling_project_uuid' => $sibling->uuid,
                'ecosystem' => $ecosystem->value,
                'finding_count' => count($findings),
            ]);
        }
    }
}
