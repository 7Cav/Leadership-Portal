<?php

namespace App\Integration\Nightwatch;

/**
 * Why the Nightwatch seam could not say who was on a WARDOGS server. Carried on
 * {@see NightwatchUnavailable} so a caller (the capture run, 7.5) can pick how to record the
 * outage from a discriminator rather than by matching message text.
 */
enum NightwatchFailureReason: string
{
    /** No API key, base URL or server id is configured — a deployment fault. */
    case Misconfigured = 'misconfigured';

    /** Connection failed: DNS, refused, timed out, or an unusable base URL. */
    case Unreachable = 'unreachable';

    /** Nightwatch answered with an error status (401/403 for a bad key, 429 for rate limits, 5xx). */
    case HttpError = 'http_error';

    /** Nightwatch answered successfully with a body we could not use. */
    case Malformed = 'malformed';
}
