<?php

// Arch testing in general
// https://pestphp.com/docs/arch-testing

// https://pestphp.com/docs/arch-testing/#content-php
// https://github.com/pestphp/pest/blob/3.x/src/ArchPresets/Php.php
arch("Code satisfies Pest's php presets")
    ->preset()
    ->php();

// https://pestphp.com/docs/arch-testing/#content-security
// https://github.com/pestphp/pest/blob/3.x/src/ArchPresets/Security.php
arch("Code satisfies Pest's security presets")
    ->preset()
    ->security()
    // Kistn\Process\ShellProcessRunner uses exec() intentionally
    // as it is an external package (path repository) tested independently.
    ->ignoring('Kistn');

// https://pestphp.com/docs/arch-testing/#content-laravel
// https://github.com/pestphp/pest/blob/3.x/src/ArchPresets/Laravel.php
arch("Code satisfies Pest's Laravel presets")
    ->preset()
    ->laravel()
    // Skip checking controller methods since the some controllers have methods
    // which are not conform with Laravel's standard names:
    // BookingController::indexWeb()
    // FileController::downloadTrace()
    ->ignoring('App\Http\Controllers');

arch('Comparison of models always uses strict equality')
    ->expect('App')
    ->toUseStrictEquality();

// Redundant since in Laravel presets as well but mandatory not to forget
test('No debugging statements are left in the code')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();
