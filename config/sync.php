<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roster sync cadence
    |--------------------------------------------------------------------------
    |
    | Cron expression the scheduler uses to dispatch SyncRosterJob (Story 2.4). The exact
    | cadence is an S6 conversation (Deferred) — this only wires the mechanism, so the value
    | lives in config/env rather than hardcoded in routes/console.php.
    |
    */

    'roster_cron' => env('ROSTER_SYNC_CRON', '0 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | LOA sync cadence and pacing
    |--------------------------------------------------------------------------
    |
    | Cron expression the scheduler uses to dispatch SyncLoasJob (Story 4.2), offset from the roster sync
    | so the two do not start together. The pause, in milliseconds, goes between requests to the forum:
    | the first run backfills a year of threads, one request each, and should not hit the forum in a burst.
    |
    */

    'loa_cron' => env('LOA_SYNC_CRON', '15 * * * *'),

    // The daily full run, which re-reads recent and open leave in case a post was edited after the hourly runs passed it (they only read a three-hour overlap).
    'loa_full_cron' => env('LOA_SYNC_FULL_CRON', '30 3 * * *'),

    'loa_request_delay_ms' => (int) env('LOA_SYNC_REQUEST_DELAY_MS', 200),

];
