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

];
