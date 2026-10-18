<?php

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    public string $name = '';
    public bool $showCreateModal = false;
    public string $sortBy = '';
    public string $sortDirection = 'asc';

    public function createProject(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $project = Auth::user()->projects()->create(['name' => $this->name]);

        $this->reset('name', 'showCreateModal');

        $this->redirect(route('projects.edit', $project), navigate: true);
    }

    /** @var list<string> */
    private const array SORTABLE_COLUMNS = ['name', 'package_count', 'last_checked_at_human', 'tokens_count'];

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    /** @return \Illuminate\Support\Collection<int, Project> */
    #[Computed]
    public function projects(): \Illuminate\Support\Collection
    {
        $projects = Auth::user()->projects()
            ->withCount('tokens')
            ->with(['ecosystems'])
            ->get()
            ->map(function (Project $project): Project {
                $project->package_count = $project->ecosystems->sum('package_count');
                $project->last_checked_at_human = $project->ecosystems
                    ->flatMap(fn ($e) => [$e->last_checked_at, $e->last_pushed_at])
                    ->filter()
                    ->max();

                return $project;
            });

        $sortColumn = in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'name';

        $sorted = $projects->sortBy(
            fn (Project $p) => $sortColumn === 'name' ? mb_strtolower($p->name) : $p->{$sortColumn},
        );

        return $this->sortDirection === 'desc' ? $sorted->values()->reverse()->values() : $sorted->values();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:subheading>{{ __('Manage your software package inventories') }}</flux:subheading>
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
        <flux:table>
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === '' || $sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Ecosystems') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'package_count'" :direction="$sortDirection" wire:click="sort('package_count')">{{ __('Packages') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'last_checked_at_human'" :direction="$sortDirection" wire:click="sort('last_checked_at_human')">{{ __('Last checked') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'tokens_count'" :direction="$sortDirection" wire:click="sort('tokens_count')">{{ __('Tokens') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach($this->projects as $project)
                    <flux:table.row :key="$project->uuid">
                        <flux:table.cell>
                            <flux:link :href="route('projects.packages', $project)" wire:navigate class="font-medium">
                                {{ $project->name }}
                            </flux:link>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach($project->ecosystems as $ecosystem)
                                    <flux:badge size="sm" :color="$ecosystem->ecosystem->badgeColor()">{{ $ecosystem->ecosystem->label() }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $project->package_count ?? 0 }}</flux:table.cell>
                        <flux:table.cell>
                            @if($project->last_checked_at_human)
                                {{ \Carbon\Carbon::parse($project->last_checked_at_human)->diffForHumans() }}
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $project->tokens_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                :href="route('projects.edit', $project)"
                                wire:navigate
                                variant="ghost"
                                size="sm"
                                icon="pencil-square"
                                :aria-label="__('Edit :name', ['name' => $project->name])"
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
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
