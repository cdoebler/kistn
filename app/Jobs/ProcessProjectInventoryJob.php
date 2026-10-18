<?php

namespace App\Jobs;

use App\Ecosystems\Contracts\EcosystemProcessor;
use App\Ecosystems\Wp\Jobs\WpOutdatedJob;
use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\Services\NotificationDispatcherService;
use App\ValueObjects\EcosystemProcessResultValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessProjectInventoryJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, array{packages: array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}>, findings?: array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>, private_packages?: list<string>, public_packages?: list<string>, advisories?: array<int, array{ecosystem: string, name: string, payload: array<int, mixed>}>}>  $ecosystems
     */
    public function __construct(
        public readonly Project $project,
        public readonly array $ecosystems,
    ) {}

    public function handle(NotificationDispatcherService $dispatcher): void
    {
        Log::debug('ProcessProjectInventory: starting', [
            'project_uuid' => $this->project->uuid,
            'ecosystems' => array_keys($this->ecosystems),
        ]);

        $wpEcosystems = [];
        $nonWpResults = [];

        foreach ($this->ecosystems as $key => $data) {
            $ecosystem = EcosystemEnum::tryFrom($key);

            if ($ecosystem === null) {
                Log::warning('ProcessProjectInventory: unknown ecosystem key, skipping', [
                    'project_uuid' => $this->project->uuid,
                    'key' => $key,
                ]);

                continue;
            }

            $processor = $this->resolveProcessor($ecosystem);

            $packages = $data['packages'];
            $findings = $data['findings'] ?? [];
            $advisories = $data['advisories'] ?? [];
            $privatePackages = $data['private_packages'] ?? [];
            $publicPackages = $data['public_packages'] ?? [];

            $result = $processor->process(
                project: $this->project,
                payloadHash: $this->computeHash($packages),
                packages: $packages,
                findings: $findings,
                advisories: $advisories,
                privatePackages: $privatePackages,
                publicPackages: $publicPackages,
            );

            if ($this->isWpEcosystem($ecosystem)) {
                $wpEcosystems[] = $ecosystem;
            } else {
                $nonWpResults[] = $result;
            }
        }

        if ($nonWpResults !== []) {
            $payload = $this->buildPayload($nonWpResults);

            if (! $payload->isEmpty()) {
                $dispatcher->dispatch($this->project, $payload);
            }
        }

        if ($wpEcosystems !== []) {
            $wpJobs = collect($wpEcosystems)
                ->map(fn (EcosystemEnum $e) => new WpOutdatedJob($this->project, $e))
                ->all();
            $chain = [...$wpJobs, new FinalizeProjectWpNotificationJob($this->project, $wpEcosystems)];

            Bus::chain($chain)->dispatch();

            Log::debug('ProcessProjectInventory: dispatched WP chain', [
                'project_uuid' => $this->project->uuid,
                'wp_ecosystems' => array_map(fn (EcosystemEnum $e) => $e->value, $wpEcosystems),
            ]);
        }
    }

    private function isWpEcosystem(EcosystemEnum $ecosystem): bool
    {
        return in_array($ecosystem, [EcosystemEnum::WpPlugin, EcosystemEnum::WpTheme, EcosystemEnum::WpCore], strict: true);
    }

    private function resolveProcessor(EcosystemEnum $ecosystem): EcosystemProcessor
    {
        /** @var EcosystemProcessor */
        return resolve($ecosystem->processorClass());
    }

    /**
     * @param  list<EcosystemProcessResultValueObject>  $results
     */
    private function buildPayload(array $results): ProjectNotificationPayloadValueObject
    {
        $outdatedByEcosystem = [];
        $findingsByEcosystem = [];

        foreach ($results as $result) {
            $outdatedByEcosystem[$result->ecosystem->value] = $result->outdatedSummary;

            if ($result->newFindings !== []) {
                $findingsByEcosystem[$result->ecosystem->value] = $result->newFindings;
            }
        }

        return new ProjectNotificationPayloadValueObject(
            outdatedByEcosystem: $outdatedByEcosystem,
            findingsByEcosystem: $findingsByEcosystem,
            triggeredAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<int, array{name: string, version: string, is_direct: bool}>  $packages
     */
    private function computeHash(array $packages): string
    {
        $sorted = $packages;
        usort($sorted, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return hash('sha256', (string) json_encode($sorted));
    }
}
