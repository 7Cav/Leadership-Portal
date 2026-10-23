<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LOA sync pacing
    |--------------------------------------------------------------------------
    |
    | The pause, in milliseconds, between requests to the forum during an LOA sync (Story 4.2): the first run
    | backfills a year of threads, one request each, and should not hit the forum in a burst. When the syncs
    | run is set in routes/console.php.
    |
    */

    'loa_request_delay_ms' => (int) env('LOA_SYNC_REQUEST_DELAY_MS', 200),

];
