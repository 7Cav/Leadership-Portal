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
        if ($this->verdict($idKind, $platformId) === false) {
            throw InvalidGameIdentityException::unknownSteamAccount();
        }
    }

    /** Whether the kind is a Steam account, so a caller can tell "not asked" from "not a Steam ID". */
    public function applies(string $idKind): bool
    {
        return SharedAccount::of($idKind) === SharedAccount::Steam;
    }

    /**
     * Steam's answer for one ID: true when it knows the account, false when it does not, null when it was not asked (not
     * a Steam kind, no key configured) or could not answer. The bulk import shows null as "not checked".
     */
    public function verdict(string $idKind, string $platformId): ?bool
    {
        if (! $this->applies($idKind) || ! $this->steam->configured()) {
            return null;
        }

        try {
            return $this->steam->accountExists($platformId);
        } catch (SteamUnavailable) {
            return null;
        }
    }
}
