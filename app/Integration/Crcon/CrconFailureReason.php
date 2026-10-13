<?php

namespace App\Integration\Crcon;

/**
 * Why the CRCON seam could not say who was on the server. Carried on {@see CrconUnavailable}
 * so a caller (the capture run, 7.5) can pick how to record the outage from a discriminator
 * rather than by matching message text.
 */
enum CrconFailureReason: string
{
    /** No base URL or API key is configured — a deployment fault. */
    case Misconfigured = 'misconfigured';

    /** Connection failed: DNS, refused, timed out, or an unusable base URL. */
    case Unreachable = 'unreachable';

    /** CRCON answered with an error status (including 401/403 for a bad key). */
    case HttpError = 'http_error';

    /** CRCON answered successfully with a body we could not use. */
    case Malformed = 'malformed';
}
