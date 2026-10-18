<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use Database\Factories\AdvisorySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property EcosystemEnum $ecosystem
 * @property string $name
 * @property array<mixed>|null $advisories
 * @property Carbon $checked_at
 * @property Carbon $expires_at
 */
#[Fillable(['ecosystem', 'name', 'advisories', 'checked_at', 'expires_at'])]
#[WithoutTimestamps]
class AdvisorySnapshot extends Model
{
    /** @use HasFactory<AdvisorySnapshotFactory> */
    use HasFactory;

    #[\Override]
    protected function casts(): array
    {
        return [
            'ecosystem' => EcosystemEnum::class,
            'advisories' => 'array',
            'checked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
