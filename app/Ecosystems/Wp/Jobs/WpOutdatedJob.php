<?php

namespace App\Ecosystems\Wp\Jobs;

use App\Ecosystems\Wp\Clients\WpOrgClient;
use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Services\SiblingOutdatedNotifierService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class WpOutdatedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly EcosystemEnum $ecosystem,
    ) {}

    public function handle(WpOrgClient $client, SiblingOutdatedNotifierService $siblingOutdatedNotifier): void
    {
        Log::debug('Starting WpOutdatedJob', [ // @pest-mutate-ignore logging not asserted in this test suite
            'project-name' => $this->project->name,
            'project-uuid' => $this->project->uuid,
            'ecosystem' => $this->ecosystem->value,
        ]);

        /** @var list<string> $names */
        $names = ProjectPackage::query()
            ->where('project_packages.project_id', $this->project->id)
            ->join('packages', 'project_packages.package_id', '=', 'packages.id')
            ->where('packages.ecosystem', $this->ecosystem->value)
            // Confirmed-private packages are, by definition, not in the wordpress.org
            // directory — checking wp.org for them is meaningless at best, and at worst
            // (if the slug collides with an unrelated later-published listing) corrupts
            // latest_version with a different product's version stream.
            ->where(function ($query): void {
                $query->whereNull('packages.is_private')->orWhere('packages.is_private', false);
            })
            ->pluck('packages.name')
            ->map(fn (mixed $v): string => is_string($v) ? $v : '')
            ->filter(fn (string $v): bool => $v !== '')
            ->values()
            ->all();

        if ($names === []) {
            Log::debug('WpOutdatedJob: no packages found', [ // @pest-mutate-ignore logging not asserted in this test suite
                'project_uuid' => $this->project->uuid,
                'ecosystem' => $this->ecosystem->value,
            ]);

            return;
        }

        /** @var array<string, string> $latestVersions */
        $latestVersions = [];

        foreach ($names as $name) {
            $version = match ($this->ecosystem) {
                EcosystemEnum::WpPlugin => $client->latestPluginVersion($name),
                EcosystemEnum::WpTheme => $client->latestThemeVersion($name),
                EcosystemEnum::WpCore => $client->latestCoreVersion(),
                default => null,
            };

            if ($version !== null) {
                $latestVersions[$name] = $version;
            }
        }

        Log::debug('WpOutdatedJob: fetched latest versions', [ // @pest-mutate-ignore logging not asserted in this test suite
            'project_uuid' => $this->project->uuid,
            'ecosystem' => $this->ecosystem->value,
            'fetched_count' => count($latestVersions),
            'skipped_count' => count($names) - count($latestVersions),
        ]);

        if ($latestVersions === []) {
            return;
        }

        $changed = $this->updateLatestVersions($latestVersions);

        if ($changed !== []) {
            $siblingOutdatedNotifier->notify($this->project, $this->ecosystem, $changed);
        }
    }

    /**
     * @param  array<string, string>  $latestVersions
     * @return list<string> Names whose latest_version value actually changed
     */
    private function updateLatestVersions(array $latestVersions): array
    {
        $unmatched = [];
        $changed = [];

        foreach ($latestVersions as $name => $latest) {
            // Only write when the value actually differs, so updated_at isn't
            // touched every run for an unchanged latest_version.
            $affected = Package::query()
                ->where('ecosystem', $this->ecosystem->value)
                ->where('name', $name)
                ->where(function ($query) use ($latest): void {
                    $query->whereNull('latest_version')->orWhere('latest_version', '!=', $latest);
                })
                ->update(['latest_version' => $latest]);

            if ($affected > 0) {
                $changed[] = $name;
            } elseif (! Package::query()->where('ecosystem', $this->ecosystem->value)->where('name', $name)->exists()) {
                $unmatched[] = $name;
            }
        }

        if ($unmatched !== []) {
            Log::warning('WpOutdatedJob: packages not found in DB', [ // @pest-mutate-ignore logging not asserted in this test suite
                'project_uuid' => $this->project->uuid,
                'ecosystem' => $this->ecosystem->value,
                'unmatched' => $unmatched,
            ]);
        }

        return $changed;
    }
}
