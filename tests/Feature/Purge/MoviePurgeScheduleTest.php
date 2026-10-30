<?php

use Illuminate\Support\Facades\Artisan;

/*
 * Subject III.3: a movie nobody watched for a month has to be erased. The `scheduler`
 * container does it with cron; this is the same sweep scheduled by the application, so
 * an installation started with `composer run dev` erases those files too.
 */
test('the sweep of the movies left unwatched for a month is scheduled every day', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->toContain('movies:purge-stale')
        ->toMatch('/\d+ \d+ \* \* \*/');
});
