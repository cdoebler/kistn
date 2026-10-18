<?php

namespace App\Ecosystems\Wp;

use App\Ecosystems\BaseEcosystemProcessor;
use App\Ecosystems\Wp\Clients\WpOrgClient;
use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Services\PackagePrivacyService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Shared private/public reconciliation for WP ecosystems.
 *
 * WP packages carry a registry-membership flag the client derives from WordPress
 * core's own update transient. Absence from the wordpress.org directory (private)
 * scopes the package to a per-project row; confirmed presence (public) migrates it
 * back to the shared global row so cross-project vuln sharing keeps working. The
 * actual transition/merge logic lives in PackagePrivacyService — it's ecosystem
 * agnostic so the same code path also backs the manual "mark as private"/"reset
 * registry status" actions in the packages UI for every ecosystem.
 */
abstract class AbstractWpProcessor extends BaseEcosystemProcessor
{
    /** @param  list<string>  $privatePackages */
    protected function processPrivatePackages(Project $project, array $privatePackages): void
    {
        if ($privatePackages === []) {
            return;
        }

        $service = resolve(PackagePrivacyService::class);
        $now = now();

        foreach ($privatePackages as $name) {
            $package = $this->findPackageForProject($project, $name);

            if ($package === null) {
                continue;
            }

            $service->markPrivate($project, $package, $now);
        }
    }

    /** @param  list<string>  $publicPackages */
    protected function processPublicPackages(Project $project, array $publicPackages): void
    {
        if ($publicPackages === []) {
            return;
        }

        $now = now();

        foreach ($publicPackages as $name) {
            $this->markPackagePublic($project, $name, $now);
        }
    }

    /**
     * Confirms a package as public by name — used both from the public-packages list
     * and from a WPScan advisory snapshot arriving for this ecosystem's own slug.
     */
    protected function markPackagePublic(Project $project, string $name, CarbonInterface $now): void
    {
        $package = Package::query()
            ->where('project_id', $project->id)
            ->where('ecosystem', $this->ecosystem()->value)
            ->where('name', $name)
            ->first() ?? Package::query()
            ->where('project_id', 0)
            ->where('ecosystem', $this->ecosystem()->value)
            ->where('name', $name)
            ->first();

        if ($package === null) {
            return;
        }

        if ($package->project_id !== 0 && $this->predatesDirectoryListing($package, $name)) {
            Log::warning('AbstractWpProcessor: refusing to auto-publish package that predates its wordpress.org directory listing', [
                'project_uuid' => $project->uuid,
                'ecosystem' => $this->ecosystem()->value,
                'package_name' => $name,
            ]);

            return;
        }

        resolve(PackagePrivacyService::class)->markPublic($project, $package, $now);
    }

    /**
     * A package sharing a slug with a wordpress.org listing added *after* the package
     * was already tracked here is almost certainly a different, unrelated plugin that
     * happens to reuse the same slug (e.g. a vendor later publishing a free companion
     * plugin under the same name as their existing premium/self-hosted one). Merging
     * it into the global row would compare the installed plugin's version against a
     * product it has nothing to do with. Fails open (returns false) when the directory
     * added-date can't be determined, preserving prior behavior.
     */
    private function predatesDirectoryListing(Package $package, string $name): bool
    {
        $client = resolve(WpOrgClient::class);

        $addedAt = $this->ecosystem() === EcosystemEnum::WpTheme
            ? $client->themeAddedDate($name)
            : $client->pluginAddedDate($name);

        if ($addedAt === null || $package->created_at === null) {
            return false;
        }

        try {
            $directoryAddedAt = Date::parse($addedAt);
        } catch (\Throwable) {
            return false;
        }

        return $package->created_at->lt($directoryAddedAt);
    }
}
