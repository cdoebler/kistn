<?php

namespace App\Ecosystems\WpPlugin\Processors;

use App\Ecosystems\Wp\AbstractWpProcessor;
use App\Enums\EcosystemEnum;
use App\Models\AdvisorySnapshot;
use App\Models\Project;

class WpPluginProcessor extends AbstractWpProcessor
{
    public static function registryUrl(string $packageName): string
    {
        return "https://wordpress.org/plugins/{$packageName}";
    }

    protected function ecosystem(): EcosystemEnum
    {
        return EcosystemEnum::WpPlugin;
    }

    /**
     * @param  list<array{ecosystem: string, name: string, payload: array<int, mixed>}>  $advisories
     * @param  list<string>  $privatePackages
     * @param  list<string>  $publicPackages
     */
    #[\Override]
    protected function processSpecific(Project $project, array $advisories, array $privatePackages, array $publicPackages = []): void
    {
        $this->processPrivatePackages($project, $privatePackages);
        $this->processPublicPackages($project, $publicPackages);
        $this->processAdvisories($project, $advisories);
    }

    /** @param  array<int, array{ecosystem: string, name: string, payload: array<int, mixed>}>  $advisories */
    private function processAdvisories(Project $project, array $advisories): void
    {
        if ($advisories === []) {
            return;
        }

        $now = now();

        foreach ($advisories as $advisory) {
            /** @var int $ttl */
            $ttl = config(
                'inventory.advisory_snapshot_ttl.'.$advisory['ecosystem'],
                60 * 60 * 24,
            );

            AdvisorySnapshot::updateOrCreate(
                ['ecosystem' => $advisory['ecosystem'], 'name' => $advisory['name']],
                [
                    'advisories' => $advisory['payload'],
                    'checked_at' => $now,
                    'expires_at' => $now->clone()->addSeconds($ttl),
                ],
            );

            // A snapshot for this ecosystem's own slug means WPScan knows it — it is public.
            if ($advisory['ecosystem'] === $this->ecosystem()->value) {
                $this->markPackagePublic($project, $advisory['name'], $now);
            }
        }
    }
}
