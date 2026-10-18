<?php

namespace App\Http\Controllers\Api;

use App\Enums\EcosystemEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\InventoryEventResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = $project->inventoryEvents()->latest('occurred_at');

        $ecosystem = $request->query('ecosystem');

        if (is_string($ecosystem)) {
            $eco = EcosystemEnum::tryFrom($ecosystem);

            if ($eco === null) {
                abort(422, 'Invalid ecosystem filter.');
            }

            $query->where('ecosystem', $eco->value);
        }

        return InventoryEventResource::collection($query->paginate(50));
    }
}
