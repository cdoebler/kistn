<?php

namespace App\Jobs;

use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\Models\VulnFinding;
use App\Services\NotificationDispatcherService;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FinalizeProjectWpNotificationJob implements ShouldQueue
{
    use Queueable;

    /** @param  list<EcosystemEnum>  $wpEcosystems */
    public function __construct(
        public readonly Project $project,
        public readonly array $wpEcosystems,
    ) {}

    public function handle(NotificationDispatcherService $dispatcher): void
    {
        Log::debug('FinalizeProjectWpNotification: starting', [
            'project_uuid' => $this->project->uuid,
            'wp_ecosystems' => array_map(fn (EcosystemEnum $e) => $e->value, $this->wpEcosystems),
        ]);

        $outdatedByEcosystem = [];
        $findingsByEcosystem = [];
        $since = $this->project->last_notified_at;

        $onlyUnnotified = $this->project->wantsDeltaDigest();

        foreach ($this->wpEcosystems as $ecosystem) {
            $outdated = $this->project->outdatedPackages($ecosystem, $onlyUnnotified);

            if ($outdated !== []) {
                $outdatedByEcosystem[$ecosystem->value] = OutdatedSummaryValueObject::fromPackages($outdated);
            }

            $findings = $this->queryNewFindings($ecosystem, $since);

            if ($findings !== []) {
                $findingsByEcosystem[$ecosystem->value] = $findings;
            }
        }

        if ($outdatedByEcosystem === [] && $findingsByEcosystem === []) {
            Log::debug('FinalizeProjectWpNotification: nothing to notify', [
                'project_uuid' => $this->project->uuid,
            ]);

            return;
        }

        $payload = new ProjectNotificationPayloadValueObject(
            outdatedByEcosystem: $outdatedByEcosystem,
            findingsByEcosystem: $findingsByEcosystem,
            triggeredAt: CarbonImmutable::now(),
        );

        $dispatcher->dispatch($this->project, $payload);
    }

    /**
     * @return list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>
     */
    private function queryNewFindings(EcosystemEnum $ecosystem, ?CarbonInterface $since): array
    {
        $query = $this->project->vulnFindings()
            ->whereHas('package', fn ($q) => $q->where('ecosystem', $ecosystem->value));

        if ($since !== null) {
            $query->where('first_seen_at', '>', $since);
        }

        /** @var list<array{package_name: string, package_version: string, advisory_id: string, severity: string}> */
        return array_values($query->get()
            ->map(fn (VulnFinding $f): array => [
                'package_name' => $f->package_name,
                'package_version' => $f->package_version,
                'advisory_id' => $f->advisory_id,
                'severity' => $f->severity->value,
            ])->all());
    }
}
