<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use Database\Factories\VulnFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SeverityEnum $severity
 * @property VulnSourceEnum $source
 * @property EcosystemEnum $ecosystem proxied from package relation
 * @property string $package_name proxied from package relation
 */
#[Fillable([
    'package_id',
    'package_version',
    'advisory_id',
    'severity',
    'source',
    'first_seen_at',
    'last_seen_at',
])]
#[WithoutTimestamps]
#[RouteKey('uuid')]
class VulnFinding extends Model
{
    /** @use HasFactory<VulnFindingFactory> */
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
            'severity' => SeverityEnum::class,
            'source' => VulnSourceEnum::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return Attribute<EcosystemEnum, never> */
    protected function ecosystem(): Attribute
    {
        return Attribute::make(
            get: fn (): EcosystemEnum => $this->package->ecosystem ?? EcosystemEnum::Composer, // @pest-mutate-ignore package_id is a non-nullable FK with cascadeOnDelete — the relation can never be null
        );
    }

    /** @return Attribute<string, never> */
    protected function packageName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->package->name ?? '', // @pest-mutate-ignore package_id is a non-nullable FK with cascadeOnDelete — the relation can never be null
        );
    }
}
