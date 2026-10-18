<?php

namespace App\Services;

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class SiblingOutdatedNotifierService
{
    public function __construct(
        private readonly NotificationDispatcherService $dispatcher,
    ) {}

    /**
     * @param  list<string>  $changedPackageNames  Names whose packages.latest_version value just changed
     */
    public function notify(Project $actingProject, EcosystemEnum $ecosystem, array $changedPackageNames): void
    {
        if ($changedPackageNames === []) {
            return;
        }

        $packageIds = Package::query()
            ->where('project_id', 0)
            ->where('ecosystem', $ecosystem->value)
            ->whereIn('name', $changedPackageNames)
            ->pluck('id');

        if ($packageIds->isEmpty()) {
            return; // every changed name was a private-only package — no siblings possible
        }

        $siblingProjectIds = ProjectPackage::query()
            ->whereIn('package_id', $packageIds)
            ->where('project_id', '!=', $actingProject->id)
            ->distinct()
            ->pluck('project_id');

        foreach ($siblingProjectIds as $siblingProjectId) {
            /** @var int $siblingProjectId */
            $sibling = Project::find($siblingProjectId);

            if ($sibling === null) {
                continue;
            }

            $outdated = $sibling->outdatedPackages($ecosystem, $sibling->wantsDeltaDigest());

            if ($outdated === []) {
                continue;
            }

            $payload = new ProjectNotificationPayloadValueObject(
                outdatedByEcosystem: [$ecosystem->value => OutdatedSummaryValueObject::fromPackages($outdated)],
                findingsByEcosystem: [],
                triggeredAt: CarbonImmutable::now(),
            );

            $this->dispatcher->dispatch($sibling, $payload);

            Log::debug('SiblingOutdatedNotifier: notified sibling project of shared latest_version change', [
                'acting_project_uuid' => $actingProject->uuid,
                'sibling_project_uuid' => $sibling->uuid,
                'ecosystem' => $ecosystem->value,
                'package_count' => count($outdated),
            ]);
        }
    }
}
