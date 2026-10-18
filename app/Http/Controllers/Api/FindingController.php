<?php

namespace App\Http\Controllers\Api;

use App\Enums\EcosystemEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\VulnFindingResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FindingController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = $project->vulnFindings()->with('package')->latest('last_seen_at');

        $ecosystem = $request->query('ecosystem');

        if (is_string($ecosystem)) {
            $eco = EcosystemEnum::tryFrom($ecosystem);

            if ($eco === null) {
                abort(422, 'Invalid ecosystem filter.');
            }

            $query->whereHas('package', fn ($q) => $q->where('ecosystem', $eco->value));
        }

        return VulnFindingResource::collection($query->paginate(50));
    }
}
