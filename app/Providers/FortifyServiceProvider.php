<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip());

            return Limit::perMinute(Config::integer('fortify.rate_limits.login'))->by($throttleKey);
        });

        // Coarse, route-aware net applied to every Fortify route via
        // config('fortify.middleware'). Login/2FA/passkeys keep their own tighter
        // limiters above; this covers the routes Fortify leaves unthrottled —
        // password reset, verification resend, registration, profile/password
        // updates. Sensitive mail/credential routes get a per-email+IP bucket;
        // everything else falls back to a generous per-IP net.
        RateLimiter::for('auth', function (Request $request): Limit {
            $ip = $request->ip() ?? 'unknown';
            $emailKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$ip);

            return match ($request->route()?->getName()) {
                'password.email' => Limit::perMinute(Config::integer('fortify.rate_limits.password_email'))->by($emailKey),
                'password.update' => Limit::perMinute(Config::integer('fortify.rate_limits.password_reset'))->by($emailKey),
                'verification.send' => Limit::perMinute(Config::integer('fortify.rate_limits.verification'))->by(($request->user()->id ?? $ip).'|verify'),
                'register' => Limit::perMinute(Config::integer('fortify.rate_limits.register'))->by($ip.'|register'),
                default => Limit::perMinute(Config::integer('fortify.rate_limits.auth'))->by($ip),
            };
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->string('credential.id')->toString();

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
