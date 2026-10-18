<?php

namespace App\Models;

use App\Enums\EcosystemEnum;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $project_id 0 = global/public; real project id = private/per-project
 * @property EcosystemEnum $ecosystem
 * @property string|null $source_url
 * @property string|null $author
 * @property bool|null $is_private null = unknown, true = confirmed private, false = confirmed public
 * @property Carbon|null $advisory_checked_at
 */
#[Fillable(['project_id', 'ecosystem', 'name', 'latest_version', 'source_url', 'author', 'is_private', 'advisory_checked_at'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'ecosystem' => EcosystemEnum::class,
            'is_private' => 'boolean',
            'advisory_checked_at' => 'datetime',
        ];
    }

    /** @return HasMany<ProjectPackage, $this> */
    public function projectPackages(): HasMany
    {
        return $this->hasMany(ProjectPackage::class);
    }

    /**
     * Resolve package names to IDs for a project's ecosystem, preferring the global
     * (project_id=0) catalog row over a private per-project row when both exist.
     *
     * @param  list<string>  $names
     * @return array<string, int> Package name => id
     */
    public static function resolveIdsForProject(Project $project, EcosystemEnum $ecosystem, array $names): array
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            return [];
        }

        /** @var Collection<string, ?Package> $grouped */
        $grouped = self::query()
            ->where('ecosystem', $ecosystem->value)
            ->whereIn('name', $names)
            ->where(function ($q) use ($project): void {
                $q->where('project_id', 0)->orWhere('project_id', $project->id);
            })
            ->get()
            ->groupBy('name')
            ->map(fn (Collection $group): ?Package => $group->firstWhere('project_id', 0) ?? $group->first());

        return $grouped
            ->filter()
            ->map(fn (Package $p): int => $p->id)
            ->all();
    }
}
