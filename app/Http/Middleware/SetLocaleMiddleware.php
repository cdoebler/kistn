<?php

namespace App\Http\Middleware;

use App\Enums\LocaleEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request, Auth::user()?->localeOverride()));

        return $next($request);
    }

    public static function resolve(Request $request, ?LocaleEnum $override): string
    {
        $supported = array_column(LocaleEnum::cases(), 'value');

        return $override->value ?? $request->getPreferredLanguage($supported) ?? Config::string('app.locale');
    }
}
