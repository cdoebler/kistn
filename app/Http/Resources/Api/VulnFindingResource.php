<?php

namespace App\Http\Resources\Api;

use App\Models\VulnFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VulnFindingResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var VulnFinding $model */
        $model = $this->resource;

        return [
            'id' => $model->uuid,
            'ecosystem' => $model->ecosystem->value,
            'package_name' => $model->package_name,
            'package_version' => $model->package_version,
            'advisory_id' => $model->advisory_id,
            'severity' => $model->severity->value,
            'source' => $model->source->value,
            'first_seen_at' => $model->first_seen_at,
            'last_seen_at' => $model->last_seen_at,
        ];
    }
}
