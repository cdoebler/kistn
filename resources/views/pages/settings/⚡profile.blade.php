<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\LocaleEnum;
/* @chisel-email-verification */
use Illuminate\Contracts\Auth\MustVerifyEmail;
/* @end-chisel-email-verification */
use App\Http\Middleware\SetLocaleMiddleware;
use Flux\Flux;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $timezone = 'UTC';
    public string $locale = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->timezone = Auth::user()->timezone();
        $this->locale = Auth::user()->localeOverride()?->value ?? '';
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'timezone' => ['required', 'timezone'],
            // Rule::enum() + 'nullable' would reject '' (nullable only skips other rules for
            // actual null, not ''), and '' is exactly what the "Automatic" option submits —
            // so allow '' explicitly alongside the real locale values instead.
            'locale' => [Rule::in(['', ...array_column(LocaleEnum::cases(), 'value')])],
        ]);

        $user->fill(['name' => $validated['name'], 'email' => $validated['email']]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $previousLocale = $user->settings['locale'] ?? null;

        $settings = $user->settings ?? [];
        $settings['timezone'] = $validated['timezone'];
        $settings['locale'] = $validated['locale'] ?: null;
        $user->settings = $settings;

        $user->save();

        $localeChanged = $settings['locale'] !== $previousLocale;

        if ($localeChanged) {
            // The current request already resolved its locale (via SetLocaleMiddleware)
            // before this save, so __() below would otherwise still use the old one.
            App::setLocale(SetLocaleMiddleware::resolve(request(), $user->localeOverride()));
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));

        // Locale is applied by SetLocaleMiddleware per-request; a Livewire AJAX
        // response doesn't re-render the already-loaded page, so force a fresh
        // navigation to pick up the new locale immediately.
        if ($localeChanged) {
            $this->redirect(route('profile.edit'), navigate: true);
        }
    }

    /* @chisel-email-verification */
    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
    /* @end-chisel-email-verification */
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />


                {{-- @chisel-email-verification --}}
                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
                {{-- @end-chisel-email-verification --}}
            </div>

            <flux:select wire:model="timezone" :label="__('Timezone')">
                @foreach (timezone_identifiers_list() as $tz)
                    <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="locale" :label="__('Language')">
                <flux:select.option value="">{{ __('Automatic (detect from browser)') }}</flux:select.option>
                @foreach (\App\Enums\LocaleEnum::cases() as $localeCase)
                    <flux:select.option value="{{ $localeCase->value }}">{{ $localeCase->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        {{-- @chisel-email-verification --}}
        @if ($this->showDeleteUser)
        {{-- @end-chisel-email-verification --}}
            <livewire:pages::settings.delete-user-form />
        {{-- @chisel-email-verification --}}
        @endif
        {{-- @end-chisel-email-verification --}}
    </x-pages::settings.layout>
</section>
