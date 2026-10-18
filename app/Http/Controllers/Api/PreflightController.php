<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WpPreflightRequest;
use App\Models\AdvisorySnapshot;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class PreflightController extends Controller
{
    public function wp(WpPreflightRequest $request, Project $project): JsonResponse
    {
        $packages = $request->packages();

        /** @var Collection<string, AdvisorySnapshot> $snapshots */
        $snapshots = collect();

        if ($packages !== []) {
            /** @var Collection<int, AdvisorySnapshot> $found */
            $found = AdvisorySnapshot::query()
                ->where(function ($query) use ($packages): void {
                    foreach ($packages as $p) {
                        $query->orWhere(fn ($q) => $q
                            ->where('ecosystem', $p['ecosystem'])
                            ->where('name', $p['name'])
                        );
                    }
                })
                ->get();

            $snapshots = $found->keyBy(fn (AdvisorySnapshot $s): string => $s->ecosystem->value.':'.$s->name);
        }

        $stale = [];
        $advisories = [];

        foreach ($packages as $package) {
            $key = $package['ecosystem'].':'.$package['name'];
            $snapshot = $snapshots->get($key);

            if (! $snapshot instanceof AdvisorySnapshot || $snapshot->isExpired()) {
                $stale[] = ['ecosystem' => $package['ecosystem'], 'name' => $package['name']];
            } else {
                $advisories[] = [
                    'ecosystem' => $package['ecosystem'],
                    'name' => $package['name'],
                    'advisories' => $snapshot->advisories ?? [],
                    'expires_at' => $snapshot->expires_at->toIso8601String(),
                ];
            }
        }

        /** @var list<string> $private */
        $private = Package::query()
            ->join('project_packages', 'packages.id', '=', 'project_packages.package_id')
            ->where('project_packages.project_id', $project->id)
            ->where('packages.is_private', true)
            ->pluck('packages.name')
            ->values()
            ->all();

        return response()->json(compact('stale', 'advisories', 'private'));
    }
}
