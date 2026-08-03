<?php

namespace App\Integration\Cav;

/**
 * Why enrichment could not produce a roster profile.
 *
 * This exists because the failure modes are not equally interesting. A forum member who holds
 * no milpac is a normal, expected population, and reporting each of their logins as an error
 * would bury the one case that needs attention — a roster outage — in noise that operators
 * learn to ignore. The reason travels on the exception so the decision of *how loudly* to
 * record a failure is made from a discriminator rather than by matching message strings.
 *
 * It is also the input Story 1.7 needs to tell a user *why* they are unenriched, which is why
 * the callback stores it alongside the (absent) snapshot.
 */
enum CavFailureReason: string
{
    /** The trooper is on none of the serving rosters — expected, not a fault. */
    case NotOnRoster = 'not_on_roster';

    /** The roster carries this trooper, but their record could not be parsed. */
    case RecordUnusable = 'record_unusable';

    /** Connection failed: DNS, refused, timed out, or an unusable base URL. */
    case Unreachable = 'unreachable';

    /** The API answered with an error status. */
    case HttpError = 'http_error';

    /** The API answered successfully with a body we could not use. */
    case Malformed = 'malformed';

    /** No API key is configured — a deployment fault, not a roster fact. */
    case MissingCredential = 'missing_credential';

    /** The identity link could not be written. */
    case LinkFailed = 'link_failed';

    /**
     * Whether this warrants an error report. False for outcomes that are part of normal
     * operation, which are recorded at info level instead.
     */
    public function isFault(): bool
    {
        return $this !== self::NotOnRoster;
    }
}
