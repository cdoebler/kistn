<?php

use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Project')] class extends Component {
    #[Locked]
    public Project $project;

    public string $name = '';
    public bool $showDeleteModal = false;
    public string $tokenName = '';
    public ?string $newTokenPlainText = null;
    public bool $auditLocalComposer = false;
    public bool $auditLocalNpm = false;
    public bool $notifyImmediately = false;

    public function mount(): void
    {
        if (Auth::id() !== $this->project->user_id) {
            abort(403);
        }

        $this->name = $this->project->name;
        $this->auditLocalComposer = $this->project->auditLocalComposer();
        $this->auditLocalNpm     = $this->project->auditLocalNpm();
        $this->notifyImmediately = $this->project->notifyImmediately();
    }

    #[Computed]
    public function hasComposerEcosystem(): bool
    {
        return $this->project->ecosystems->pluck('ecosystem')->contains(\App\Enums\EcosystemEnum::Composer);
    }

    #[Computed]
    public function hasNpmEcosystem(): bool
    {
        return $this->project->ecosystems->pluck('ecosystem')->contains(\App\Enums\EcosystemEnum::Npm);
    }

    public function updateAuditSettings(): void
    {
        $this->project->update([
            'settings' => array_merge($this->project->settings ?? [], [
                'audit_local_composer' => $this->auditLocalComposer,
                'audit_local_npm'      => $this->auditLocalNpm,
            ]),
        ]);

        Flux::toast(variant: 'success', text: __('Audit settings saved.'));
    }

    public function updateNotificationSettings(): void
    {
        $this->project->update([
            'settings' => array_merge($this->project->settings ?? [], [
                'notify_immediately' => $this->notifyImmediately,
            ]),
        ]);

        Flux::toast(variant: 'success', text: __('Notification settings saved.'));
    }

    public function updateName(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $this->project->update(['name' => $this->name]);

        Flux::toast(variant: 'success', text: __('Project updated.'));
    }

    public function deleteProject(): void
    {
        $this->project->delete();

        $this->redirect(route('projects.index'), navigate: true);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> */
    #[Computed]
    public function tokens(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->project->tokens()->latest()->get();
    }

    public function createToken(): void
    {
        $this->validate(['tokenName' => ['required', 'string', 'max:255']]);

        $newToken = $this->project->createToken($this->tokenName);
        $this->newTokenPlainText = $newToken->plainTextToken;
        $this->reset('tokenName');
        unset($this->tokens);
    }

    public function dismissToken(): void
    {
        $this->newTokenPlainText = null;
        unset($this->tokens);
    }

    public function revokeToken(int $tokenId): void
    {
        $token = $this->project->tokens()->find($tokenId);

        if ($token === null) {
            return;
        }

        $token->delete();

        unset($this->tokens);

        Flux::toast(variant: 'success', text: __('Token revoked.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div>
        <flux:link :href="route('projects.index')" wire:navigate class="flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300">
            {{ __('← All Projects') }}
        </flux:link>
    </div>

    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ $project->name }}: {{ __('Settings') }}</flux:heading>
            <flux:subheading>{{ __('Manage project settings and API tokens') }}</flux:subheading>
        </div>
        <flux:button :href="route('projects.packages', $project)" wire:navigate variant="ghost" icon="archive-box">
            {{ __('View Packages') }}
        </flux:button>
    </div>

    {{-- General section --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading>{{ __('General') }}</flux:heading>
            <flux:subheading>{{ __('Basic project information') }}</flux:subheading>
        </div>

        <flux:separator />

        <flux:input wire:model="name" :label="__('Name')" />

        <div class="flex justify-end">
            <flux:button wire:click="updateName" variant="primary">{{ __('Save') }}</flux:button>
        </div>

        <flux:separator />

        <div>
            <flux:label>{{ __('Project ID') }}</flux:label>
            <flux:description class="mb-2">{{ __('Use this ID when configuring the client package.') }}</flux:description>
            <div x-data="{ uuid: @js($project->uuid), copied: false }" class="flex gap-2">
                <flux:input
                    readonly
                    value="{{ $project->uuid }}"
                    aria-label="{{ __('Project ID') }}"
                    class="font-mono"
                />
                <flux:button
                    variant="ghost"
                    aria-live="polite"
                    x-on:click="
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(uuid).then(() => { copied = true; setTimeout(() => copied = false, 2000) });
                        } else {
                            const ta = document.createElement('textarea');
                            ta.value = uuid;
                            ta.style.cssText = 'position:absolute;left:-9999px';
                            document.body.appendChild(ta);
                            ta.select();
                            document.execCommand('copy');
                            document.body.removeChild(ta);
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        }
                    "
                >
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied">{{ __('Copied!') }}</span>
                </flux:button>
            </div>
        </div>

    </flux:card>

    {{-- Audit section --}}
    @if($this->hasComposerEcosystem || $this->hasNpmEcosystem)
        <flux:card class="space-y-6">
            <div>
                <flux:heading>{{ __('Audit') }}</flux:heading>
                <flux:subheading>{{ __('Run server-side audits against uploaded lock files to detect vulnerabilities and outdated packages.') }}</flux:subheading>
            </div>

            <flux:separator />

            <div class="space-y-4">
                @if($this->hasComposerEcosystem)
                    <flux:checkbox wire:model="auditLocalComposer" :label="__('Composer audit')" :description="__('Run composer audit and composer outdated after each lock file upload.')" />
                @endif
                @if($this->hasNpmEcosystem)
                    <flux:checkbox wire:model="auditLocalNpm" :label="__('npm audit')" :description="__('Run npm audit and npm outdated after each lock file upload.')" />
                @endif
            </div>

            <div class="flex justify-end">
                <flux:button wire:click="updateAuditSettings" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </flux:card>
    @endif

    {{-- Notifications section --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading>{{ __('Notifications') }}</flux:heading>
            <flux:subheading>{{ __('Receive email notifications when vulnerabilities or outdated packages are detected.') }}</flux:subheading>
        </div>

        <flux:separator />

        <div class="space-y-4">
            <flux:switch wire:model="notifyImmediately" :label="__('Notify immediately')" :description="__('Send notifications immediately when changes are detected, overriding your global digest preference.')" />
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="updateNotificationSettings" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </flux:card>

    {{-- API Tokens section --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading>{{ __('API Tokens') }}</flux:heading>
            <flux:subheading>{{ __('Tokens authenticate client pushes. Each token is scoped to this project only.') }}</flux:subheading>
        </div>

        <flux:separator />

        {{-- Token reveal (shown immediately after creation) --}}
        @if($newTokenPlainText)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Copy your token now') }}</flux:callout.heading>
                <flux:callout.text>{{ __("This token won't be shown again. Store it somewhere safe.") }}</flux:callout.text>
            </flux:callout>
            <div x-data="{ token: @js($newTokenPlainText), copied: false }" class="flex gap-2">
                <flux:input
                    readonly
                    value="{{ $newTokenPlainText }}"
                    aria-label="{{ __('New API token') }}"
                    class="font-mono"
                />
                <flux:button
                    variant="ghost"
                    aria-live="polite"
                    x-on:click="
                        const doCopy = (text) => {
                            if (navigator.clipboard) {
                                navigator.clipboard.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 2000) });
                            } else {
                                const ta = document.createElement('textarea');
                                ta.value = text;
                                ta.style.cssText = 'position:absolute;left:-9999px';
                                document.body.appendChild(ta);
                                ta.select();
                                document.execCommand('copy');
                                document.body.removeChild(ta);
                                copied = true;
                                setTimeout(() => copied = false, 2000);
                            }
                        };
                        doCopy(token)
                    "
                >
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied">{{ __('Copied!') }}</span>
                </flux:button>
            </div>
            <flux:button wire:click="dismissToken" size="sm" variant="ghost">
                {{ __("Got it, I've saved my token") }}
            </flux:button>
        @endif

        {{-- Token list --}}
        @if($this->tokens->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column>{{ __('Last used') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($this->tokens as $token)
                        <flux:table.row :key="$token->id">
                            <flux:table.cell class="font-medium">{{ $token->name }}</flux:table.cell>
                            <flux:table.cell>{{ $token->created_at->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell>{{ $token->last_used_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button
                                    wire:click="revokeToken({{ $token->id }})"
                                    wire:confirm="{{ __('Revoke this token? Any clients using it will stop working.') }}"
                                    variant="ghost"
                                    size="sm"
                                >
                                    {{ __('Revoke') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            @if(!$newTokenPlainText)
                <flux:text>{{ __('No tokens yet.') }}</flux:text>
            @endif
        @endif

        <flux:separator />

        {{-- Create token form --}}
        <div class="flex gap-3">
            <flux:input wire:model="tokenName" label="{{ __('Token name') }}" label:sr-only :placeholder="__('Token name, e.g. ci-deploy')" class="flex-1" />
            <flux:button wire:click="createToken" variant="primary">{{ __('Create Token') }}</flux:button>
        </div>
    </flux:card>

    {{-- Danger Zone section --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading class="text-red-600 dark:text-red-400">{{ __('Danger Zone') }}</flux:heading>
            <flux:subheading>{{ __('Permanently delete this project and all its data.') }}</flux:subheading>
        </div>

        <flux:separator />

        <flux:button wire:click="$set('showDeleteModal', true)" variant="danger">
            {{ __('Delete Project') }}
        </flux:button>
    </flux:card>

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete project?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('This will permanently delete') }} <strong>{{ $project->name }}</strong>
                    {{ __('and all associated packages, events, findings, and tokens. This cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="deleteProject" variant="danger">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
