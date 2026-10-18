<?php

namespace App\Http\Resources\Api;

use App\Models\InventoryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var InventoryEvent $model */
        $model = $this->resource;

        return [
            'id' => $model->uuid,
            'ecosystem' => $model->ecosystem->value,
            'package_name' => $model->package_name,
            'action' => $model->action->value,
            'old_version' => $model->old_version,
            'new_version' => $model->new_version,
            'occurred_at' => $model->occurred_at,
        ];
    }
}
