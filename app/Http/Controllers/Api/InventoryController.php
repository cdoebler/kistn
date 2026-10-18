<?php

namespace App\Http\Controllers\Api;

use App\Enums\EcosystemEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectInventoryRequest;
use App\Jobs\ProcessProjectInventoryJob;
use App\Models\ApiInteraction;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    public function hashes(Request $request, Project $project): JsonResponse
    {
        Log::debug('InventoryController@hashes: request received', ['project_uuid' => $project->uuid]);

        $ecosystemStates = $project->ecosystems()
            ->get()
            ->keyBy('ecosystem');

        $ecosystemStates->each(function (ProjectEcosystem $state): void {
            $state->update(['last_checked_at' => now()]);
        });

        /** @var Project $authenticatedProject */
        $authenticatedProject = $request->user();

        ApiInteraction::create([
            'project_id' => $project->id,
            'token_id' => $authenticatedProject->currentAccessToken()->id,
            'endpoint' => 'GET /api/projects/{id}/hashes',
            'ecosystem' => null,
            'hash_matched' => null,
            'status' => '200',
            'occurred_at' => now(),
        ]);

        $hashes = [];
        foreach (EcosystemEnum::cases() as $ecosystem) {
            /** @var ProjectEcosystem|null $state */
            $state = $ecosystemStates->get($ecosystem->value);
            $hashes[$ecosystem->value] = $state?->current_hash;
        }

        Log::debug('InventoryController@hashes: returning hashes', [
            'project_uuid' => $project->uuid,
            'ecosystems' => array_keys(array_filter($hashes)),
        ]);

        return response()->json($hashes);
    }

    public function store(StoreProjectInventoryRequest $request, Project $project): JsonResponse
    {
        $ecosystems = $request->ecosystems();

        Log::debug('InventoryController@store: request received', [
            'project_uuid' => $project->uuid,
            'ecosystems' => array_keys($ecosystems),
        ]);

        /** @var Project $authenticatedProject */
        $authenticatedProject = $request->user();

        ApiInteraction::create([
            'project_id' => $project->id,
            'token_id' => $authenticatedProject->currentAccessToken()->id,
            'endpoint' => 'POST /api/projects/{id}/inventory',
            'ecosystem' => null,
            'hash_matched' => null,
            'status' => '202',
            'occurred_at' => now(),
        ]);

        dispatch(new ProcessProjectInventoryJob($project, $ecosystems));

        return response()->json(['message' => 'Inventory accepted for processing.'], 202);
    }
}
