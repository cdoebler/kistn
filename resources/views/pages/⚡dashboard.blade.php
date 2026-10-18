<?php

use App\Enums\InventoryActionEnum;
use App\Enums\SeverityEnum;
use App\Models\InventoryEvent;
use App\Models\Project;
use App\Models\VulnFinding;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\JoinClause;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    private const int STALE_DAYS = 14;

    public string $name = '';

    public bool $showCreateModal = false;

    public function createProject(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $project = Auth::user()->projects()->create(['name' => $this->name]);

        $this->reset('name', 'showCreateModal');

        $this->redirect(route('projects.edit', $project), navigate: true);
    }

    /** @return EloquentCollection<int, Project> */
    #[Computed]
    public function projects(): EloquentCollection
    {
        return Auth::user()->projects()->with('ecosystems')->get();
    }

    /** @return Collection<int, int> */
    #[Computed]
    public function projectIds(): Collection
    {
        return $this->projects->pluck('id');
    }

    #[Computed]
    public function packageCount(): int
    {
        return (int) $this->projects->sum(fn (Project $p): int => $p->ecosystems->sum('package_count'));
    }

    /**
     * Per-project outdated counts (installed version differs from known latest).
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function outdatedByProject(): Collection
    {
        if ($this->projectIds->isEmpty()) {
            return collect();
        }

        return DB::table('project_packages')
            ->join('packages', 'packages.id', '=', 'project_packages.package_id')
            ->whereIn('project_packages.project_id', $this->projectIds)
            ->whereNotNull('packages.latest_version')
            ->whereColumn('project_packages.version', '!=', 'packages.latest_version')
            ->select('project_packages.project_id', DB::raw('count(*) as total'))
            ->groupBy('project_packages.project_id')
            ->pluck('total', 'project_packages.project_id');
    }

    #[Computed]
    public function outdatedCount(): int
    {
        return (int) $this->outdatedByProject->sum();
    }

    /**
     * Distinct (project, finding) exposure rows the user's projects are affected by.
     *
     * @return Collection<int, object{project_id: int, id: int, severity: SeverityEnum}>
     */
    #[Computed]
    public function vulnRows(): Collection
    {
        if ($this->projectIds->isEmpty()) {
            return collect();
        }

        return VulnFinding::query()
            ->join('project_packages', function (JoinClause $join): void {
                $join->on('project_packages.package_id', '=', 'vuln_findings.package_id')
                    ->whereIn('project_packages.project_id', $this->projectIds);
            })
            ->where(function ($q): void {
                $q->whereColumn('project_packages.version', 'vuln_findings.package_version')
                    ->orWhere('vuln_findings.package_version', '*');
            })
            ->select('project_packages.project_id', 'vuln_findings.id', 'vuln_findings.severity')
            ->distinct()
            ->get()
            ->map(fn (VulnFinding $f): object => (object) [
                'project_id' => (int) $f->getAttribute('project_id'),
                'id' => (int) $f->id,
                'severity' => $f->severity,
            ]);
    }

    #[Computed]
    public function vulnCount(): int
    {
        return $this->vulnRows->pluck('id')->unique()->count();
    }

    /** @return Collection<string, int> severity value => distinct finding count */
    #[Computed]
    public function vulnBySeverity(): Collection
    {
        return $this->vulnRows
            ->unique('id')
            ->groupBy(fn (object $r): string => $r->severity->value)
            ->map(fn (Collection $g): int => $g->count());
    }

    /**
     * Top projects ranked by exposure: vulnerabilities first, then outdated packages.
     *
     * @return Collection<int, object{project: Project, vulns: int, outdated: int}>
     */
    #[Computed]
    public function needsAttention(): Collection
    {
        $vulnByProject = $this->vulnRows
            ->groupBy('project_id')
            ->map(fn (Collection $g): int => $g->pluck('id')->unique()->count());

        return $this->projects
            ->map(fn (Project $p): object => (object) [
                'project' => $p,
                'vulns' => (int) $vulnByProject->get($p->id, 0),
                'outdated' => (int) $this->outdatedByProject->get($p->id, 0),
            ])
            ->filter(fn (object $r): bool => $r->vulns > 0 || $r->outdated > 0)
            ->sortByDesc(fn (object $r): array => [$r->vulns, $r->outdated])
            ->take(5)
            ->values();
    }

    /**
     * Projects whose most recent push is older than the stale threshold.
     *
     * @return Collection<int, object{project: Project, last_pushed_at: CarbonInterface}>
     */
    #[Computed]
    public function staleProjects(): Collection
    {
        $threshold = now()->subDays(self::STALE_DAYS);

        return $this->projects
            ->map(fn (Project $p): object => (object) [
                'project' => $p,
                'last_pushed_at' => $p->ecosystems->pluck('last_pushed_at')->filter()->max(),
            ])
            ->filter(fn (object $r): bool => $r->last_pushed_at !== null && $r->last_pushed_at->lt($threshold))
            ->sortBy(fn (object $r): CarbonInterface => $r->last_pushed_at)
            ->take(5)
            ->values();
    }

    /** @return EloquentCollection<int, InventoryEvent> */
    #[Computed]
    public function recentActivity(): EloquentCollection
    {
        if ($this->projectIds->isEmpty()) {
            return new EloquentCollection;
        }

        return InventoryEvent::query()
            ->whereIn('project_id', $this->projectIds)
            ->with('project')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();
    }

    /** @return array{color: string, icon: string} */
    public function actionStyle(InventoryActionEnum $action): array
    {
        return match ($action) {
            InventoryActionEnum::Added => ['color' => 'green', 'icon' => 'plus'],
            InventoryActionEnum::Updated => ['color' => 'blue', 'icon' => 'arrow-path'],
            InventoryActionEnum::Removed => ['color' => 'red', 'icon' => 'minus'],
        };
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('Inventory and vulnerability overview across your projects') }}</flux:subheading>
        </div>
        <flux:button wire:click="$set('showCreateModal', true)" variant="primary" icon="plus">
            {{ __('New Project') }}
        </flux:button>
    </div>

    @if($this->projects->isEmpty())
        <div class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-zinc-300 p-12 dark:border-zinc-700">
            <flux:heading>{{ __('No projects yet') }}</flux:heading>
            <flux:subheading>{{ __('Create your first project to start tracking package inventories.') }}</flux:subheading>
            <flux:button wire:click="$set('showCreateModal', true)" variant="primary">{{ __('Create your first project') }}</flux:button>
        </div>
    @else
        {{-- Stat tiles --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('projects.index') }}" wire:navigate
               class="flex flex-col gap-1 rounded-xl border border-neutral-200 p-5 transition hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:border-neutral-600 dark:hover:bg-neutral-800/50">
                <flux:subheading>{{ __('Projects') }}</flux:subheading>
                <flux:heading size="xl" class="tabular-nums">{{ $this->projects->count() }}</flux:heading>
            </a>

            <div class="flex flex-col gap-1 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:subheading>{{ __('Packages') }}</flux:subheading>
                <flux:heading size="xl" class="tabular-nums">{{ number_format($this->packageCount) }}</flux:heading>
            </div>

            <div class="flex flex-col gap-1 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:subheading>{{ __('Outdated') }}</flux:subheading>
                <flux:heading size="xl" class="tabular-nums {{ $this->outdatedCount > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                    {{ number_format($this->outdatedCount) }}
                </flux:heading>
            </div>

            <div class="flex flex-col gap-1 rounded-xl border p-5 {{ $this->vulnCount > 0 ? 'border-red-300 dark:border-red-500/40' : 'border-neutral-200 dark:border-neutral-700' }}">
                <flux:subheading>{{ __('Vulnerabilities') }}</flux:subheading>
                <div class="flex items-baseline gap-3">
                    <flux:heading size="xl" class="tabular-nums {{ $this->vulnCount > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ number_format($this->vulnCount) }}
                    </flux:heading>
                    @if($this->vulnCount > 0)
                        <div class="flex flex-wrap gap-1">
                            @foreach([SeverityEnum::Critical, SeverityEnum::High, SeverityEnum::Medium, SeverityEnum::Low] as $severity)
                                @if($this->vulnBySeverity->get($severity->value, 0) > 0)
                                    <flux:badge size="sm" :color="$severity->badgeColor()">
                                        {{ $this->vulnBySeverity->get($severity->value) }} {{ $severity->label() }}
                                    </flux:badge>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Needs attention + Stale --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading class="mb-3 flex items-center gap-2">
                    <flux:icon.exclamation-triangle variant="micro" class="text-amber-500" />
                    {{ __('Needs attention') }}
                </flux:heading>
                @if($this->needsAttention->isEmpty())
                    <flux:text class="text-neutral-500">{{ __('All clear — no vulnerable or outdated packages.') }}</flux:text>
                @else
                    <div class="flex flex-col divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach($this->needsAttention as $row)
                            <a href="{{ route('projects.packages', $row->project) }}" wire:navigate
                               class="flex items-center justify-between gap-3 py-2 transition hover:text-accent">
                                <span class="truncate font-medium">{{ $row->project->name }}</span>
                                <div class="flex shrink-0 gap-1">
                                    @if($row->vulns > 0)
                                        <flux:badge size="sm" color="red">{{ $row->vulns }} {{ __('vulns') }}</flux:badge>
                                    @endif
                                    @if($row->outdated > 0)
                                        <flux:badge size="sm" color="amber">{{ $row->outdated }} {{ __('outdated') }}</flux:badge>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading class="mb-3 flex items-center gap-2">
                    <flux:icon.clock variant="micro" class="text-neutral-400" />
                    {{ __('Stale projects') }}
                </flux:heading>
                @if($this->staleProjects->isEmpty())
                    <flux:text class="text-neutral-500">{{ __('Every project pushed within the last :days days.', ['days' => 14]) }}</flux:text>
                @else
                    <div class="flex flex-col divide-y divide-neutral-100 dark:divide-neutral-800">
                        @foreach($this->staleProjects as $row)
                            <a href="{{ route('projects.packages', $row->project) }}" wire:navigate
                               class="flex items-center justify-between gap-3 py-2 transition hover:text-accent">
                                <span class="truncate font-medium">{{ $row->project->name }}</span>
                                <flux:text class="shrink-0 text-sm text-neutral-500">{{ $row->last_pushed_at->diffForHumans() }}</flux:text>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Recent activity --}}
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading class="mb-3">{{ __('Recent activity') }}</flux:heading>
            @if($this->recentActivity->isEmpty())
                <flux:text class="text-neutral-500">{{ __('No inventory changes recorded yet.') }}</flux:text>
            @else
                <div class="flex flex-col divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach($this->recentActivity as $event)
                        @php($style = $this->actionStyle($event->action))
                        <a href="{{ route('projects.packages', [$event->project, 'ecosystem' => $event->ecosystem->value]) }}" wire:navigate
                           class="flex items-center gap-3 py-2 transition hover:text-accent">
                            <flux:badge size="sm" :color="$style['color']" :icon="$style['icon']">{{ $event->action->value }}</flux:badge>
                            <span class="truncate font-mono text-sm">{{ $event->package_name }}</span>
                            <span class="ml-auto shrink-0 text-sm text-neutral-500">{{ $event->project->name }}</span>
                            <span class="shrink-0 text-sm text-neutral-400">{{ $event->occurred_at?->diffForHumans() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <flux:modal wire:model.self="showCreateModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New Project') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Give your project a name to get started.') }}</flux:text>
            </div>
            <flux:input
                wire:model="name"
                :label="__('Name')"
                placeholder="My Application"
                autofocus
            />
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button wire:click="createProject" variant="primary">
                    {{ __('Create') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
