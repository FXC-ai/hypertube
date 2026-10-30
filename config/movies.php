<?php

return [
    // Hour of the daily sweep deleting the movie files nobody watched for a month.
    // The `scheduler` container runs it through cron; the Laravel scheduler runs the
    // same command for installations started with `composer run dev`.
    'cleanup_time' => env('MOVIE_CLEANUP_TIME', '03:00'),
];
