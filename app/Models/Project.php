<?php

namespace App\Models;

use App\Enums\DigestContentEnum;
use App\Enums\EcosystemEnum;
use Database\Factories\ProjectFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_notified_at
 * @property Carbon|null $churn_flagged_at
 * @property int $churn_flagged_count
 */
#[Fillable(['user_id', 'name', 'settings', 'last_notified_at', 'churn_flagged_at', 'churn_flagged_count'])]
#[RouteKey('uuid')]
class Project extends Model implements AuthenticatableContract
{
    /** @use HasFactory<ProjectFactory> */
    use Authenticatable, HasApiTokens, HasFactory, HasUuids;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'last_notified_at' => 'datetime',
            'churn_flagged_at' => 'datetime',
            'churn_flagged_count' => 'integer',
            'settings' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::deleting(function (Project $project): void {
            Storage::disk('local')->deleteDirectory("projects/{$project->uuid}");
            $project->tokens()->delete();

            Log::info('Project deleted: removed stored files and API tokens', [
                'project_uuid' => $project->uuid,
            ]);
        });
    }

    /** @return array<int, string> */
    #[\Override]
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ProjectEcosystem, $this> */
    public function ecosystems(): HasMany
    {
        return $this->hasMany(ProjectEcosystem::class);
    }

    /** @return HasMany<ProjectPackage, $this> */
    public function projectPackages(): HasMany
    {
        return $this->hasMany(ProjectPackage::class);
    }

    /** @return HasMany<InventoryEvent, $this> */
    public function inventoryEvents(): HasMany
    {
        return $this->hasMany(InventoryEvent::class);
    }

    /** @return Builder<VulnFinding> */
    public function vulnFindings(): Builder
    {
        return VulnFinding::query()
            ->join('project_packages', function (JoinClause $join): void {
                $join->on('project_packages.package_id', '=', 'vuln_findings.package_id')
                    ->where('project_packages.project_id', $this->id);
            })
            ->where(function ($q): void {
                $q->whereColumn('project_packages.version', 'vuln_findings.package_version')
                    ->orWhere('vuln_findings.package_version', '*');
            })
            ->select('vuln_findings.*');
    }

    /**
     * Installed packages of one ecosystem whose known latest_version differs from the
     * installed one. With $onlyUnnotified, packages already reported at their current
     * latest_version are skipped — per-project state written by
     * NotificationDispatcherService, deliberately not packages.updated_at: that row is
     * shared across projects and rewritten by pushes, WpOutdatedJob and privacy
     * migrations, so its timestamp kept re-entering known-outdated packages into the
     * daily digest.
     *
     * @return array<string, array{version: string, latest_version: string}>
     */
    public function outdatedPackages(EcosystemEnum $ecosystem, bool $onlyUnnotified = false): array
    {
        $query = DB::table('project_packages')
            ->join('packages', 'project_packages.package_id', '=', 'packages.id')
            ->where('project_packages.project_id', $this->id)
            ->where('packages.ecosystem', $ecosystem->value)
            ->whereNotNull('packages.latest_version')
            ->whereColumn('packages.latest_version', '!=', 'project_packages.version')
            ->orderBy('packages.name')
            ->select(['packages.name', 'project_packages.version', 'packages.latest_version']);

        if ($onlyUnnotified) {
            $query->where(fn (QueryBuilder $q): QueryBuilder => $q->whereNull('project_packages.notified_latest_version')
                ->orWhereColumn('project_packages.notified_latest_version', '!=', 'packages.latest_version'));
        }

        $outdated = [];

        /** @var object{name: string, version: string, latest_version: string} $row */
        foreach ($query->get() as $row) {
            $outdated[$row->name] = ['version' => $row->version, 'latest_version' => $row->latest_version];
        }

        return $outdated;
    }

    public function wantsDeltaDigest(): bool
    {
        $this->loadMissing('user');

        return $this->user?->digestContent() === DigestContentEnum::Delta;
    }

    /** @return HasMany<ApiInteraction, $this> */
    public function apiInteractions(): HasMany
    {
        return $this->hasMany(ApiInteraction::class);
    }

    public function ecosystemState(EcosystemEnum $ecosystem): ?ProjectEcosystem
    {
        return $this->ecosystems()->where('ecosystem', $ecosystem->value)->first();
    }

    public function auditLocalComposer(): bool
    {
        return (bool) ($this->settings['audit_local_composer'] ?? false);
    }

    public function auditLocalNpm(): bool
    {
        return (bool) ($this->settings['audit_local_npm'] ?? false);
    }

    public function notifyImmediately(): bool
    {
        return (bool) ($this->settings['notify_immediately'] ?? false);
    }
}
