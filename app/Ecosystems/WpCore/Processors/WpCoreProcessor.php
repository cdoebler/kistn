<?php

namespace App\Ecosystems\WpCore\Processors;

use App\Ecosystems\BaseEcosystemProcessor;
use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;

/**
 * WordPress core has no directory-membership concept — every wp-core package is
 * inherently public, so it's confirmed as such unconditionally instead of relying
 * on client-reported private_packages/public_packages (which the wp-client always
 * sends empty for wp-core; see AbstractWpProcessor for the plugin/theme equivalent).
 */
class WpCoreProcessor extends BaseEcosystemProcessor
{
    public static function registryUrl(string $packageName): string
    {
        return 'https://wordpress.org/download/releases/';
    }

    protected function ecosystem(): EcosystemEnum
    {
        return EcosystemEnum::WpCore;
    }

    /**
     * @param  array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}  $data
     */
    #[\Override]
    protected function resolvePackage(Project $project, string $name, array $data): Package
    {
        $package = $this->findPackageForProject($project, $name);

        if ($package !== null) {
            return $package;
        }

        return Package::firstOrCreate(
            ['project_id' => 0, 'ecosystem' => $this->ecosystem()->value, 'name' => $name],
            [
                'source_url' => $data['source_url'] ?? null,
                'author' => $data['author'] ?? null,
                'is_private' => false,
                'advisory_checked_at' => now(),
            ],
        );
    }

    /**
     * @param  array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function buildPackageUpdates(Package $package, array $data): array
    {
        $updates = parent::buildPackageUpdates($package, $data);

        if ($package->is_private !== false) {
            $updates['is_private'] = false;
            $updates['advisory_checked_at'] = now();
        }

        return $updates;
    }
}
