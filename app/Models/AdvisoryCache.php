<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use Database\Factories\AdvisoryCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $checked_at
 * @property Carbon $ttl_expires_at
 */
#[Fillable(['ecosystem', 'name', 'version', 'advisories', 'checked_at', 'ttl_expires_at'])]
#[WithoutTimestamps]
class AdvisoryCache extends Model
{
    /** @use HasFactory<AdvisoryCacheFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'ecosystem' => EcosystemEnum::class,
            'advisories' => 'array',
            'checked_at' => 'datetime',
            'ttl_expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->ttl_expires_at->isPast();
    }
}
