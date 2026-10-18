<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use App\Enums\VersionStatusEnum;
use Carbon\CarbonInterface;
use Database\Factories\ProjectPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $version installed version
 * @property string|null $notified_latest_version latest_version last included in a notification for this project
 * @property VersionStatusEnum $version_status installed vs latest, derived
 * @property EcosystemEnum $ecosystem proxied from Package relation
 * @property string $name proxied from Package relation
 * @property string|null $latest_version proxied from Package relation
 * @property string|null $source_url proxied from Package relation
 * @property bool|null $is_private proxied from Package relation
 * @property CarbonInterface|null $advisory_checked_at proxied from Package relation
 */
#[Fillable(['project_id', 'package_id', 'version', 'notified_latest_version', 'is_direct', 'is_dev', 'is_active', 'is_child', 'depth'])]
#[RouteKey('uuid')]
class ProjectPackage extends Model
{
    /** @use HasFactory<ProjectPackageFactory> */
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
            'is_direct' => 'boolean',
            'is_dev' => 'boolean',
            'is_active' => 'boolean',
            'is_child' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    // Accessors proxy shared fields so templates work without explicit relation access.
    // JOIN-based queries populate these directly as model attributes (accessor is bypassed);
    // relation-based queries fall through to the Package relation.

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (isset($this->attributes['name']) && is_string($this->attributes['name'])) {
                    return $this->attributes['name'];
                }

                return $this->package->name ?? '';
            },
        );
    }

    /** @return Attribute<EcosystemEnum, never> */
    protected function ecosystem(): Attribute
    {
        return Attribute::make(
            get: function (): EcosystemEnum {
                $raw = $this->attributes['ecosystem'] ?? null;

                if ($raw !== null && (is_string($raw) || is_int($raw))) {
                    return EcosystemEnum::from($raw);
                }

                return $this->package->ecosystem ?? EcosystemEnum::Composer;
            },
        );
    }

    /** @return Attribute<string|null, never> */
    protected function latestVersion(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (array_key_exists('latest_version', $this->attributes)) {
                    $value = $this->attributes['latest_version'];

                    return is_string($value) ? $value : null;
                }

                return $this->package?->latest_version;
            },
        );
    }

    /** @return Attribute<VersionStatusEnum, never> */
    protected function versionStatus(): Attribute
    {
        return Attribute::make(
            get: fn (): VersionStatusEnum => VersionStatusEnum::fromVersions($this->version, $this->latest_version),
        );
    }

    /** @return Attribute<string|null, never> */
    protected function sourceUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (array_key_exists('source_url', $this->attributes)) {
                    $value = $this->attributes['source_url'];

                    return is_string($value) ? $value : null;
                }

                return $this->package?->source_url;
            },
        );
    }

    /** @return Attribute<bool|null, never> */
    protected function isPrivate(): Attribute
    {
        return Attribute::make(
            get: function (): ?bool {
                if (array_key_exists('is_private', $this->attributes)) {
                    $value = $this->attributes['is_private'];

                    return $value === null ? null : (bool) $value;
                }

                return $this->package?->is_private;
            },
        );
    }

    /** @return Attribute<CarbonInterface|null, never> */
    protected function advisoryCheckedAt(): Attribute
    {
        return Attribute::make(
            get: function (): ?CarbonInterface {
                if (array_key_exists('advisory_checked_at', $this->attributes)) {
                    $value = $this->attributes['advisory_checked_at'];

                    return is_string($value) ? new Carbon($value) : null;
                }

                return $this->package?->advisory_checked_at;
            },
        );
    }
}
