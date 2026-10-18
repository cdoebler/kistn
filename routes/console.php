<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('kistn:audit --all')->dailyAt('02:00');
Schedule::command('notifications:send-digest')->everyMinute();
Schedule::command('kistn:detect-churn')->dailyAt('03:00');
