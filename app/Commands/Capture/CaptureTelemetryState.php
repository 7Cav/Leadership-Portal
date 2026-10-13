<?php

namespace App\Commands\Capture;

/** The two ways a practice ends up without server data (7.9). */
enum CaptureTelemetryState: string
{
    case NotConfigured = 'not_configured';
    case Failed = 'failed';
}
