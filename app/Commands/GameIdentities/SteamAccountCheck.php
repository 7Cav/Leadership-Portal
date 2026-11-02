<?php

namespace App\Commands\GameIdentities;

use App\Integration\Steam\SteamApi;
use App\Integration\Steam\SteamUnavailable;
use App\Models\SharedAccount;

/**
 * Asks Steam whether a Steam ID being linked belongs to an account (#174), and refuses one Steam does not know. Only a
 * kind that is a Steam account ({@see SharedAccount}) is asked about. With no key configured, or Steam unreachable or
 * slow, the ID is saved as before, unchecked: a Steam outage never stops a leader linking a trooper.
 */
class SteamAccountCheck
{
    public function __construct(private readonly SteamApi $steam) {}

    /** @throws InvalidGameIdentityException when Steam answers that it has no account with this ID. */
    public function check(string $idKind, string $platformId): void
    {
        if (SharedAccount::of($idKind) !== SharedAccount::Steam) {
            return;
        }

        try {
            $exists = $this->steam->accountExists($platformId);
        } catch (SteamUnavailable) {
            return;
        }

        if (! $exists) {
            throw InvalidGameIdentityException::unknownSteamAccount();
        }
    }
}
