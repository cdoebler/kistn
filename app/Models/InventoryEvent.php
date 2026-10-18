<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use Database\Factories\InventoryEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EcosystemEnum $ecosystem
 * @property InventoryActionEnum $action
 */
#[Fillable([
    'project_id',
    'ecosystem',
    'package_name',
    'action',
    'old_version',
    'new_version',
    'occurred_at',
])]
#[WithoutTimestamps]
#[RouteKey('uuid')]
class InventoryEvent extends Model
{
    /** @use HasFactory<InventoryEventFactory> */
    use HasFactory, HasUuids;

    /** @return array<int, string> */
    #[\Override]
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'ecosystem' => EcosystemEnum::class,
            'action' => InventoryActionEnum::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
