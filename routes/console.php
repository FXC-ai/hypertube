<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The `scheduler` container does the same sweep with cron; scheduling it here as well
// keeps an installation running without Docker, where `php artisan schedule:work` is
// the only scheduler around.
Schedule::command('movies:purge-stale')
    ->dailyAt((string) config('movies.cleanup_time'))
    ->name('movies-purge-stale');
