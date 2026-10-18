<?php

use App\Models\Project;
use App\Models\VulnFinding;
use App\Services\PackagePrivacyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Packages')]
class extends Component {
    use WithPagination;

    #[Locked]
    public Project $project;

    #[Url]
    public string $ecosystem = '';

    #[Url]
    public bool $onlyVulnerable = false;

    public string $perPage = '20';

    public string $sortBy = '';

    public string $sortDirection = 'asc';

    public bool $hasDepthAttribute = false;

    public bool $isWpEcosystem = false;

    public function mount(): void
    {
        if (Auth::id() !== $this->project->user_id) {
            abort(403);
        }

        $this->perPage = session('packages.per_page', '20');

        if ($this->ecosystem === '') {
            $this->ecosystem = $this->ecosystemCounts->keys()->first() ?? '';
        }

        $this->hasDepthAttribute = ! str_starts_with($this->ecosystem, 'wp-');
        $this->isWpEcosystem = str_starts_with($this->ecosystem, 'wp-');
    }

    public function updatedEcosystem(): void
    {
        $this->resetPage();
        $this->hasDepthAttribute = ! str_starts_with($this->ecosystem, 'wp-');
        $this->isWpEcosystem = str_starts_with($this->ecosystem, 'wp-');
    }

    public function updatedPerPage(): void
    {
        session(['packages.per_page' => $this->perPage]);
        $this->resetPage();
    }

    public function updatedOnlyVulnerable(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public ?string $selectedPackageUuid = null;

    public function openPackageDetail(string $uuid): void
    {
        $this->selectedPackageUuid = $uuid;
        $this->js('$flux.modal("package-detail").show()');
    }

    public function closePackageDetail(): void
    {
        $this->selectedPackageUuid = null;
    }

    public function markPackagePrivate(): void
    {
        if ($this->selectedPackageUuid === null) {
            return;
        }

        $pp = $this->project->projectPackages()
            ->where('project_packages.uuid', $this->selectedPackageUuid)
            ->with('package')
            ->firstOrFail();

        (new PackagePrivacyService)->markPrivate($this->project, $pp->package);

        unset($this->selectedPackage, $this->packages, $this->activeFindingsBySeverity);
    }

    public function resetAdvisoryStatus(): void
    {
        if ($this->selectedPackageUuid === null) {
            return;
        }

        $pp = $this->project->projectPackages()
            ->where('project_packages.uuid', $this->selectedPackageUuid)
            ->with('package')
            ->firstOrFail();

        (new PackagePrivacyService)->markPublic($this->project, $pp->package);

        unset($this->selectedPackage, $this->packages, $this->activeFindingsBySeverity);
    }

    #[Computed]
    public function selectedPackage(): ?\App\Models\ProjectPackage
    {
        if ($this->selectedPackageUuid === null) {
            return null;
        }

        return $this->project->projectPackages()
                             ->with('package')
                             ->where('project_packages.uuid', $this->selectedPackageUuid)
                             ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\VulnFinding>
     */
    #[Computed]
    public function selectedPackageFindings(): \Illuminate\Support\Collection
    {
        if ($this->selectedPackage === null) {
            return collect();
        }

        $installedVersion = $this->selectedPackage->version;

        return \App\Models\VulnFinding::query()
                             ->where('package_id', $this->selectedPackage->package_id)
                             ->get()
                             ->sort(function (\App\Models\VulnFinding $a, \App\Models\VulnFinding $b) use ($installedVersion): int {
                                 $aIsCurrent = $a->package_version === $installedVersion ? 1 : 0;
                                 $bIsCurrent = $b->package_version === $installedVersion ? 1 : 0;
                                 if ($aIsCurrent !== $bIsCurrent) {
                                     return $bIsCurrent <=> $aIsCurrent;
                                 }

                                 return $b->severity->priority() <=> $a->severity->priority();
                             })
                             ->values();
    }

    /**
     * Distinct (ecosystem, project_package, finding) exposure rows for the whole project,
     * regardless of which ecosystem tab or page is currently displayed.
     *
     * @return \Illuminate\Support\Collection<int, object{ecosystem: string, project_package_id: int, finding_id: int}>
     */
    #[Computed]
    public function projectVulnFindings(): \Illuminate\Support\Collection
    {
        return VulnFinding::query()
            ->join('project_packages', function (JoinClause $join): void {
                $join->on('project_packages.package_id', '=', 'vuln_findings.package_id')
                    ->where('project_packages.project_id', $this->project->id);
            })
            ->join('packages', 'packages.id', '=', 'vuln_findings.package_id')
            ->where(function ($q): void {
                $q->whereColumn('project_packages.version', 'vuln_findings.package_version')
                    ->orWhere('vuln_findings.package_version', '*');
            })
            ->select('packages.ecosystem as pkg_ecosystem', 'project_packages.id as project_package_id', 'vuln_findings.id as finding_id')
            ->distinct()
            ->get()
            ->map(fn (VulnFinding $f): object => (object) [
                'ecosystem' => (string) $f->getAttribute('pkg_ecosystem'),
                'project_package_id' => (int) $f->getAttribute('project_package_id'),
                'finding_id' => (int) $f->getAttribute('finding_id'),
            ]);
    }

    /** @return \Illuminate\Support\Collection<string, int> distinct finding count per ecosystem */
    #[Computed]
    public function vulnCountsByEcosystem(): \Illuminate\Support\Collection
    {
        return $this->projectVulnFindings
            ->groupBy('ecosystem')
            ->map(fn (\Illuminate\Support\Collection $g): int => $g->pluck('finding_id')->unique()->count());
    }

    /** @return \Illuminate\Support\Collection<int, int> project_package ids with a finding in the current ecosystem tab */
    #[Computed]
    public function vulnerableProjectPackageIds(): \Illuminate\Support\Collection
    {
        return $this->projectVulnFindings
            ->where('ecosystem', $this->ecosystem)
            ->pluck('project_package_id')
            ->unique();
    }

    /** @return \Illuminate\Support\Collection<string, int> */
    #[Computed]
    public function ecosystemCounts(): \Illuminate\Support\Collection
    {
        return $this->project->projectPackages()
                             ->join('packages', 'packages.id', '=', 'project_packages.package_id')
                             ->selectRaw('packages.ecosystem, count(*) as total')
                             ->groupBy('packages.ecosystem')
                             ->orderBy('packages.ecosystem')
                             ->pluck('total', 'packages.ecosystem');
    }

    #[Computed]
    public function currentEcosystemState(): ?\App\Models\ProjectEcosystem
    {
        if ($this->ecosystem === '') {
            return null;
        }

        $eco = \App\Enums\EcosystemEnum::tryFrom($this->ecosystem);

        return $eco !== null ? $this->project->ecosystemState($eco) : null;
    }

    #[Computed]
    public function packages(): LengthAwarePaginator
    {
        $query = $this->ecosystem === ''
            ? $this->project->projectPackages()->whereRaw('0 = 1')
            : $this->project->projectPackages()
                            ->join('packages', 'packages.id', '=', 'project_packages.package_id')
                            ->where('packages.ecosystem', $this->ecosystem)
                            ->select('project_packages.*', 'packages.name', 'packages.ecosystem',
                                'packages.latest_version', 'packages.is_private', 'packages.source_url');

        if ($this->onlyVulnerable) {
            $query->whereIn('project_packages.id', $this->vulnerableProjectPackageIds);
        }

        $sortColumns = [
            'name' => 'packages.name',
            'version' => 'project_packages.version',
            'latest_version' => 'packages.latest_version',
            'depth' => 'project_packages.depth',
        ];

        if ($this->sortBy !== '' && isset($sortColumns[$this->sortBy])) {
            $query->orderBy($sortColumns[$this->sortBy], $this->sortDirection);
        } else {
            $query->orderByDesc('project_packages.is_direct')->orderBy('packages.name');
        }

        $perPage = $this->perPage === 'all' ? ($query->count() ?: 1) : (int) $this->perPage;

        return $query->paginate($perPage);
    }

    /**
     * @return \Illuminate\Support\Collection<string, \App\Models\VulnFinding>
     */
    #[Computed]
    public function activeFindingsBySeverity(): \Illuminate\Support\Collection
    {
        $items = collect($this->packages->items());
        $names = $items->pluck('name')->all();

        if ($names === [] || $this->ecosystem === '') {
            return collect();
        }

        return $this->project->vulnFindings()
                             ->whereHas('package', fn ($q) => $q->where('ecosystem', $this->ecosystem)->whereIn('name', $names))
                             ->get()
                             ->groupBy('package_name')
                             ->map(fn(\Illuminate\Support\Collection $findings): \App\Models\VulnFinding => $findings
                                 ->sortByDesc(fn(\App\Models\VulnFinding $f): int => $f->severity->priority())
                                 ->first()
                             );
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div>
        <flux:link :href="route('projects.index', $project)" wire:navigate
                   class="flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300">
            {{ __('← All Projects') }}
        </flux:link>
    </div>

    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ $project->name }}: {{ __('Packages') }}</flux:heading>
            <flux:subheading>{{ __('All resolved packages tracked for this project') }}</flux:subheading>
            @if($this->currentEcosystemState?->last_pushed_at !== null)
                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Last push:') }} {{ $this->currentEcosystemState->last_pushed_at->translatedFormat('d M Y, H:i') }}
                </flux:text>
            @endif
        </div>
        <flux:button :href="route('projects.edit', $project)" wire:navigate variant="ghost"
                     icon="adjustments-horizontal">
            {{ __('Project Settings') }}
        </flux:button>
    </div>

    @if($this->ecosystemCounts->isEmpty())
        <div
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-zinc-300 p-12 dark:border-zinc-700">
            <flux:heading>{{ __('No packages yet') }}</flux:heading>
            <flux:subheading>{{ __('Push an inventory to get started.') }}</flux:subheading>
        </div>
    @else
        <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-700" role="tablist">
            @foreach($this->ecosystemCounts as $ecoValue => $count)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $ecosystem === $ecoValue ? 'true' : 'false' }}"
                    wire:click="$set('ecosystem', '{{ $ecoValue }}')"
                    wire:key="ecosystem-tab-{{ $ecoValue }}"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors {{ $ecosystem === $ecoValue ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
                >
                    {{ \App\Enums\EcosystemEnum::from($ecoValue)->label() }} ({{ $count }})
                    @php $ecoVulnCount = $this->vulnCountsByEcosystem->get($ecoValue, 0) @endphp
                    @if($ecoVulnCount > 0)
                        <flux:badge size="sm" color="red">
                            {{ trans_choice(':count vuln|:count vulns', $ecoVulnCount, ['count' => $ecoVulnCount]) }}
                        </flux:badge>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="flex items-center justify-end">
            <flux:switch wire:model.live="onlyVulnerable" :label="__('Only vulnerable')" />
        </div>

        <flux:table :paginate="$this->packages">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('Name') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'version'" :direction="$sortDirection" wire:click="sort('version')">{{ __('Version') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'latest_version'" :direction="$sortDirection" wire:click="sort('latest_version')">{{ __('Latest') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                @if($this->ecosystem === \App\Enums\EcosystemEnum::WpTheme->value)
                    <flux:table.column>{{ __('Child') }}</flux:table.column>
                @endif
                @if($this->hasDepthAttribute)
                    <flux:table.column sortable :sorted="$sortBy === 'depth'" :direction="$sortDirection" wire:click="sort('depth')">{{ __('Depth') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Vulnerabilities') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @if($onlyVulnerable && $this->packages->isEmpty())
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No vulnerable packages in this ecosystem.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endif
                @foreach($this->packages as $package)
                    <flux:table.row :key="$package->uuid" wire:click="openPackageDetail('{{ $package->uuid }}')"
                                    class="cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800">
                        <flux:table.cell>
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono">{{ $package->name }}</span>
                                @if($package->is_private === true)
                                    <flux:badge size="sm" color="amber">{{ __('Private') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono">{{ $package->version }}</flux:table.cell>
                        <flux:table.cell class="font-mono">
                            @if($package->latest_version !== null)
                                <span class="{{ $package->version_status->textClasses() }}"
                                      title="{{ $package->version_status->label() }}">
                                    {{ $package->latest_version }} {{ $package->version_status->arrow() }}
                                    @if($package->version_status->label() !== null)
                                        <span class="sr-only">({{ $package->version_status->label() }})</span>
                                    @endif
                                </span>
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">—</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-1.5">
                                <flux:text>{{ ($package->is_direct) ? __('Direct') : __('Dep') }}</flux:text>
                                @if($package->is_dev)
                                    <flux:badge size="sm" color="zinc">{{ __('dev') }}</flux:badge>
                                @endif
                                @if($package->is_active === false)
                                    <flux:badge size="sm" color="zinc">{{ __('inactive') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        @if($this->ecosystem === \App\Enums\EcosystemEnum::WpTheme->value)
                            <flux:table.cell>
                                @if($package->is_child)
                                    <flux:badge size="sm" color="zinc">{{ __('child') }}</flux:badge>
                                @else
                                    <span class="text-zinc-500 dark:text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>
                        @endif
                        @if($this->hasDepthAttribute)
                            <flux:table.cell>{{ $package->depth ?? '—' }}</flux:table.cell>
                        @endif
                        <flux:table.cell>
                            @php $topFinding = $this->activeFindingsBySeverity->get($package->name) @endphp
                            @if($topFinding !== null)
                                <flux:badge :color="$topFinding->severity->badgeColor()" size="sm">
                                    {{ $topFinding->severity->label() }}
                                </flux:badge>
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400 text-sm">—</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="flex items-center justify-end gap-2">
            <flux:text id="per-page-label" size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Per page') }}</flux:text>
            <flux:select wire:model.live="perPage" size="xs" class="w-20" aria-labelledby="per-page-label">
                <flux:select.option value="20">20</flux:select.option>
                <flux:select.option value="50">50</flux:select.option>
                <flux:select.option value="100">100</flux:select.option>
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            </flux:select>
        </div>

        <flux:modal name="package-detail" class="md:w-[560px]" x-on:close="$wire.closePackageDetail()">
            @if($this->selectedPackage !== null)
                <div class="flex flex-col gap-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            @php
                                $isWpPackage = in_array($this->selectedPackage->ecosystem, [\App\Enums\EcosystemEnum::WpCore, \App\Enums\EcosystemEnum::WpPlugin, \App\Enums\EcosystemEnum::WpTheme]);
                                $hasDepthAttribute = ! $isWpPackage;
                                $hasChildAttribute = ($this->selectedPackage->ecosystem === \App\Enums\EcosystemEnum::WpTheme);
                                $packageUrl = match (true) {
                                    $this->selectedPackage->package?->is_private === true => null,
                                    ! $isWpPackage => $this->selectedPackage->ecosystem->registryUrl($this->selectedPackage->name),
                                    $this->selectedPackage->source_url !== null => $this->selectedPackage->source_url,
                                    $this->selectedPackage->package?->is_private === false => $this->selectedPackage->ecosystem->registryUrl($this->selectedPackage->name),
                                    default => null,
                                };
                            @endphp
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg" class="font-mono">{{ $this->selectedPackage->name }}</flux:heading>
                                @if($this->selectedPackage->package?->is_private === true)
                                    <flux:badge size="sm" color="amber">{{ __('Private') }}</flux:badge>
                                @endif
                            </div>
                            @if($packageUrl !== null)
                                <flux:link :href="$packageUrl" target="_blank" rel="noopener"
                                           class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $packageUrl }}
                                </flux:link>
                            @else
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Not in public registry') }}</flux:text>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Version') }}</flux:text>
                            <flux:text class="font-mono">{{ $this->selectedPackage->version }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Latest') }}</flux:text>
                            @if($this->selectedPackage->latest_version !== null)
                                <flux:text
                                    class="font-mono {{ $this->selectedPackage->version_status->textClasses() }}"
                                    :title="$this->selectedPackage->version_status->label()">
                                    {{ $this->selectedPackage->latest_version }} {{ $this->selectedPackage->version_status->arrow() }}
                                    @if($this->selectedPackage->version_status->label() !== null)
                                        <span class="sr-only">({{ $this->selectedPackage->version_status->label() }})</span>
                                    @endif
                                </flux:text>
                            @else
                                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Unknown') }}</flux:text>
                            @endif
                        </div>
                        <div>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</flux:text>
                            <div class="flex items-center gap-1.5">
                                <flux:text
                                    class="text-zinc-500 dark:text-zinc-400">{{ ($this->selectedPackage->is_direct) ? __('Direct') : __('Dep') }}</flux:text>
                                @if($this->selectedPackage->is_dev)
                                    <flux:badge size="sm" color="zinc">{{ __('dev') }}</flux:badge>
                                @endif
                                @if($this->selectedPackage->is_active === false)
                                    <flux:badge size="sm" color="zinc">{{ __('inactive') }}</flux:badge>
                                @endif
                            </div>
                        </div>
                        @if($hasChildAttribute)
                            <div>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Child theme') }}</flux:text>
                                <flux:text>{{ $this->selectedPackage->is_child ? __('Yes') : __('No') }}</flux:text>
                            </div>
                        @endif
                        @if($hasDepthAttribute)
                            <div>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Depth') }}</flux:text>
                                <flux:text>{{ $this->selectedPackage->depth ?? '—' }}</flux:text>
                            </div>
                        @endif
                        <div class="col-span-2">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Package data updated') }}</flux:text>
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $this->selectedPackage->package?->updated_at?->translatedFormat('d M Y, H:i') ?? '—' }}
                            </flux:text>
                        </div>

                        @if($this->selectedPackage->package?->is_private !== null)
                            <div class="col-span-2">
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    @if($this->selectedPackage->package?->is_private === true)
                                        {{ __('Not in public registry.') }}
                                        @if($this->selectedPackage->package?->advisory_checked_at !== null)
                                            {{ __('Confirmed') }} {{ $this->selectedPackage->package->advisory_checked_at->translatedFormat('d M Y, H:i') }}.
                                        @endif
                                    @else
                                        {{ __('Confirmed in public registry.') }}
                                        @if($this->selectedPackage->package?->advisory_checked_at !== null)
                                            {{ $this->selectedPackage->package->advisory_checked_at->translatedFormat('d M Y, H:i') }}.
                                        @endif
                                    @endif
                                </flux:text>
                            </div>
                        @endif
                        <div class="col-span-2 flex gap-2">
                            @if($this->selectedPackage->package?->is_private === null && $this->selectedPackage->source_url === null)
                                <flux:button wire:click="markPackagePrivate" size="sm" variant="ghost" icon="lock-closed">
                                    {{ __('Mark as private') }}
                                </flux:button>
                            @endif
                            @if($this->selectedPackage->package?->is_private !== null)
                                <flux:button wire:click="resetAdvisoryStatus" size="sm" variant="ghost" icon="arrow-path">
                                    {{ __('Reset registry status') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    @if($this->selectedPackageFindings->isNotEmpty())
                        <div>
                            <flux:heading size="sm" class="mb-3">{{ __('Vulnerabilities') }}</flux:heading>
                            <div class="flex flex-col gap-2">
                                @foreach($this->selectedPackageFindings as $finding)
                                    @php $isCurrentVersion = $finding->package_version === $this->selectedPackage->version; @endphp
                                    <div class="flex items-start justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:badge :color="$finding->severity->badgeColor()" size="sm">
                                                {{ $finding->severity->label() }}
                                            </flux:badge>
                                            @php $advisoryUrl = (new \App\ValueObjects\AdvisoryIdValueObject($finding->advisory_id))->url(); @endphp
                                            @if($advisoryUrl !== null)
                                                <flux:link :href="$advisoryUrl" target="_blank" rel="noopener"
                                                           class="font-mono text-sm">
                                                    {{ $finding->advisory_id }}
                                                </flux:link>
                                            @else
                                                <span class="font-mono text-sm">{{ $finding->advisory_id }}</span>
                                            @endif
                                            <flux:text size="sm" class="font-mono text-zinc-500 dark:text-zinc-400">v{{ $finding->package_version }}</flux:text>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            @if($isCurrentVersion)
                                                <flux:badge size="sm" color="blue">{{ __('Current') }}</flux:badge>
                                            @endif
                                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                                {{ $finding->source->label() }}
                                            </flux:text>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No vulnerabilities found.') }}</flux:text>
                    @endif
                </div>
            @endif
        </flux:modal>
    @endif
</div>
