<?php

use App\Enums\DigestContentEnum;
use App\Enums\NotificationModeEnum;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification settings')] class extends Component {
    public string $notificationMode = '';
    public string $digestTime = '08:00';
    public string $digestContent = '';
    public bool $notifyWhenEmpty = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->notificationMode = $user->notificationMode()->value;
        $this->digestTime = $user->digestTime();
        $this->digestContent = $user->digestContent()->value;
        $this->notifyWhenEmpty = $user->notifyWhenEmpty();
    }

    public function updateNotificationSettings(): void
    {
        $validated = $this->validate([
            'notificationMode' => ['required', 'in:immediate,daily_digest'],
            'digestTime' => ['required', 'date_format:H:i'],
            'digestContent' => ['required', 'in:delta,full_state'],
            'notifyWhenEmpty' => ['boolean'],
        ]);

        $user = Auth::user();
        $settings = $user->settings ?? [];
        $settings['notification_mode'] = $validated['notificationMode'];
        $settings['digest_time'] = $validated['digestTime'];
        $settings['digest_content'] = $validated['digestContent'];
        $settings['notify_when_empty'] = $validated['notifyWhenEmpty'];
        $user->settings = $settings;
        $user->save();

        Flux::toast(variant: 'success', text: __('Notification settings updated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Configure how and when you receive notifications')">
        <form wire:submit="updateNotificationSettings" class="my-6 w-full space-y-6">
            <flux:select wire:model="notificationMode" :label="__('Notification mode')">
                <flux:select.option value="{{ \App\Enums\NotificationModeEnum::Immediate->value }}">{{ __('Immediate') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\NotificationModeEnum::DailyDigest->value }}">{{ __('Daily digest') }}</flux:select.option>
            </flux:select>

            <div x-show="$wire.notificationMode === 'daily_digest'" class="space-y-6">
                <flux:input wire:model="digestTime" :label="__('Digest time')" type="time" />

                <flux:select wire:model="digestContent" :label="__('Digest content')">
                    <flux:select.option value="{{ \App\Enums\DigestContentEnum::Delta->value }}">{{ __('Changes only') }}</flux:select.option>
                    <flux:select.option value="{{ \App\Enums\DigestContentEnum::FullState->value }}">{{ __('Full state') }}</flux:select.option>
                </flux:select>

                <flux:switch wire:model="notifyWhenEmpty" :label="__('Notify when nothing changed')" :description="__('Send a short digest even when there\'s nothing new to report, so a missing email isn\'t mistaken for a problem.')" />
            </div>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
