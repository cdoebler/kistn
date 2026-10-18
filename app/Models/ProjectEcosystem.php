<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use Database\Factories\ProjectEcosystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property EcosystemEnum $ecosystem
 * @property Carbon|null $last_audited_at
 */
#[Fillable([
    'project_id',
    'ecosystem',
    'current_hash',
    'last_checked_at',
    'last_pushed_at',
    'last_audited_at',
    'package_count',
    'is_stale',
])]
#[RouteKey('uuid')]
class ProjectEcosystem extends Model
{
    /** @use HasFactory<ProjectEcosystemFactory> */
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
            'last_checked_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'last_audited_at' => 'datetime',
            'is_stale' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
