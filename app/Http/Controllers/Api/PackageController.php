<?php

namespace App\Http\Controllers\Api;

use App\Enums\EcosystemEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = $project->projectPackages()
            ->join('packages', 'project_packages.package_id', '=', 'packages.id')
            ->with('package')
            ->orderBy('packages.name');

        $ecosystem = $request->query('ecosystem');

        if (is_string($ecosystem)) {
            $eco = EcosystemEnum::tryFrom($ecosystem);

            if ($eco === null) {
                abort(422, 'Invalid ecosystem filter.');
            }

            $query->where('packages.ecosystem', $eco->value);
        }

        return PackageResource::collection($query->paginate(50));
    }
}
