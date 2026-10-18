<?php

namespace App\Http\Resources\Api;

use App\Models\ProjectPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var ProjectPackage $model */
        $model = $this->resource;

        return [
            'id' => $model->uuid,
            'ecosystem' => $model->ecosystem->value,
            'name' => $model->name,
            'version' => $model->version,
            'is_direct' => $model->is_direct,
            'is_dev' => $model->is_dev,
            'is_active' => $model->is_active,
            'depth' => $model->depth,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }
}
