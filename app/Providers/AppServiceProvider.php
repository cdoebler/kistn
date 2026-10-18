<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        Model::automaticallyEagerLoadRelationships();
        Model::preventLazyLoading(
            ! app()->isProduction()
        );

        Model::shouldBeStrict();

        URL::forceHttps($this->app->isProduction());

        Vite::useAggressivePrefetching();
    }

    /**
     * Configure per-token rate limits on inventory push/preflight endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('inventory-push', fn (Request $request) => Limit::perMinute(
            Config::integer('inventory.rate_limits.inventory')
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('inventory-files', fn (Request $request) => Limit::perMinute(
            Config::integer('inventory.rate_limits.files')
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('inventory-preflight', fn (Request $request) => Limit::perMinute(
            Config::integer('inventory.rate_limits.preflight')
        )->by($this->rateLimitKey($request)));
    }

    /**
     * Resolve the per-token (or per-IP fallback) key used to bucket rate limits.
     */
    private function rateLimitKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? (string) $user->id : ($request->ip() ?? 'unknown');
    }
}
