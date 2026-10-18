<?php

namespace App\Models;

use App\Enums\DigestContentEnum;
use App\Enums\LocaleEnum;
use App\Enums\NotificationModeEnum;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/** @property array<string, mixed>|null $settings */
#[Fillable(['name', 'email', 'password', 'settings', 'last_digest_sent_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_digest_sent_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            // Deleted individually (not truncated) so each Project's own
            // `deleting` event fires and cleans up its stored files/tokens.
            $user->projects->each->delete();

            Log::info('User deleted: removed owned projects', [
                'user_id' => $user->id,
            ]);
        });
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function notificationMode(): NotificationModeEnum
    {
        return NotificationModeEnum::tryFrom($this->strSetting('notification_mode')) ?? NotificationModeEnum::Immediate;
    }

    public function digestTime(): string
    {
        return $this->strSetting('digest_time', '08:00');
    }

    public function timezone(): string
    {
        return $this->strSetting('timezone', 'UTC');
    }

    public function localeOverride(): ?LocaleEnum
    {
        return LocaleEnum::tryFrom($this->strSetting('locale'));
    }

    public function preferredLocale(): string
    {
        $override = $this->localeOverride();

        return $override->value ?? Config::string('app.locale');
    }

    public function digestContent(): DigestContentEnum
    {
        return DigestContentEnum::tryFrom($this->strSetting('digest_content')) ?? DigestContentEnum::Delta;
    }

    public function notifyWhenEmpty(): bool
    {
        return (bool) ($this->settings['notify_when_empty'] ?? false);
    }

    private function strSetting(string $key, string $default = ''): string
    {
        $value = $this->settings[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
