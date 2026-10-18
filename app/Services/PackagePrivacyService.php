<?php

namespace App\Services;

use App\Events\PackagePublishedEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Ecosystem-agnostic private/public package transitions.
 *
 * A package lives at project_id=0 (global — shared across projects for cross-project
 * vuln-finding sharing) or scoped to a single project's project_id (private —
 * structurally isolated). Both transition directions repoint every project_packages
 * row and carry source_url/author/latest_version along with the migration so a
 * package's registry metadata never goes stale across the transition.
 */
class PackagePrivacyService
{
    public function markPrivate(Project $project, Package $package, ?CarbonInterface $now = null): Package
    {
        $now ??= now();

        if ($package->project_id !== 0) {
            // Refresh advisory_checked_at unconditionally (the client reconfirms privacy
            // on every push, and that confirmation timestamp should stay current) but
            // without touching updated_at when is_private didn't change, so updated_at
            // keeps meaning "a real change happened".
            if ($package->is_private === true) {
                Package::withoutTimestamps(fn () => $package->update(['advisory_checked_at' => $now]));
            } else {
                $package->update(['is_private' => true, 'advisory_checked_at' => $now]);
            }

            return $package;
        }

        $perProjectPackage = Package::firstOrCreate(
            ['project_id' => $project->id, 'ecosystem' => $package->ecosystem->value, 'name' => $package->name],
            [
                'source_url' => $package->source_url,
                'author' => $package->author,
                'latest_version' => $package->latest_version,
                'is_private' => true,
                'advisory_checked_at' => $now,
            ],
        );

        if (! $perProjectPackage->wasRecentlyCreated) {
            // Same updated_at concern as the branch above: the global row can keep
            // existing (another project still uses it) while this project's push
            // keeps reporting the package private, re-running this migration path
            // every day against an already-private per-project row.
            if ($perProjectPackage->is_private === true) {
                Package::withoutTimestamps(fn () => $perProjectPackage->update(['advisory_checked_at' => $now]));
            } else {
                $perProjectPackage->update(['is_private' => true, 'advisory_checked_at' => $now]);
            }
        }

        ProjectPackage::where('project_id', $project->id)
            ->where('package_id', $package->id)
            ->update(['package_id' => $perProjectPackage->id]);

        if (ProjectPackage::where('package_id', $package->id)->doesntExist()) {
            $package->delete();
        }

        Log::info('PackagePrivacyService: package marked private', [
            'project_uuid' => $project->uuid,
            'ecosystem' => $perProjectPackage->ecosystem->value,
            'package_name' => $perProjectPackage->name,
        ]);

        return $perProjectPackage;
    }

    public function markPublic(Project $project, Package $package, ?CarbonInterface $now = null): Package
    {
        $now ??= now();

        if ($package->project_id === 0) {
            if ($package->is_private !== false) {
                $package->update(['is_private' => false, 'advisory_checked_at' => $now]);

                Log::info('PackagePrivacyService: global package confirmed public', [
                    'project_uuid' => $project->uuid,
                    'ecosystem' => $package->ecosystem->value,
                    'package_name' => $package->name,
                ]);
            }

            return $package;
        }

        $globalPackage = Package::firstOrCreate(
            ['project_id' => 0, 'ecosystem' => $package->ecosystem->value, 'name' => $package->name],
            [
                'source_url' => $package->source_url,
                'author' => $package->author,
                'latest_version' => $package->latest_version,
                'is_private' => false,
                'advisory_checked_at' => $now,
            ],
        );

        if (! $globalPackage->wasRecentlyCreated) {
            $globalPackage->update([
                'is_private' => false,
                'advisory_checked_at' => $now,
                'source_url' => $package->source_url,
                'author' => $package->author,
                'latest_version' => $package->latest_version,
            ]);
        }

        ProjectPackage::where('project_id', $project->id)
            ->where('package_id', $package->id)
            ->update(['package_id' => $globalPackage->id]);

        if (ProjectPackage::where('package_id', $package->id)->doesntExist()) {
            $package->delete();
        }

        event(new PackagePublishedEvent($project, $globalPackage->ecosystem, $globalPackage));

        Log::info('PackagePrivacyService: package published (private → public)', [
            'project_uuid' => $project->uuid,
            'ecosystem' => $globalPackage->ecosystem->value,
            'package_name' => $globalPackage->name,
        ]);

        return $globalPackage;
    }
}
