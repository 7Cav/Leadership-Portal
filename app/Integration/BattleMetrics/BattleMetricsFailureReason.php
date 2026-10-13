<?php

namespace App\Integration\BattleMetrics;

/**
 * Why the BattleMetrics seam could not say who was on the server. Carried on
 * {@see BattleMetricsUnavailable} so a caller (the capture run, 7.5) can pick how to record the
 * outage from a discriminator rather than by matching message text.
 */
enum BattleMetricsFailureReason: string
{
    /** No API key or server id is configured — a deployment fault. */
    case Misconfigured = 'misconfigured';

    /** Connection failed: DNS, refused, timed out, or an unusable base URL. */
    case Unreachable = 'unreachable';

    /** BattleMetrics answered with an error status (including 401/403 for a bad key, 429 for rate limits). */
    case HttpError = 'http_error';

    /** BattleMetrics answered successfully with a body we could not use. */
    case Malformed = 'malformed';
}
