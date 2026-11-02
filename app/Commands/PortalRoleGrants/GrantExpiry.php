<?php

namespace App\Commands\PortalRoleGrants;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The end date a granter chose for a staff grant. `ends`: the grant is revoked for good once the date has passed.
 * `renew`: it lapses then and is kept until someone renews it, by the same period (`renewEveryDays`, the days from the
 * day it was set to the chosen day) or to a new date. The date is a Zulu day; the grant counts until its last second.
 */
final class GrantExpiry
{
    public const MODES = ['none', 'ends', 'renew'];

    private function __construct(
        public readonly CarbonImmutable $expiresAt,
        public readonly ?int $renewEveryDays,
    ) {}

    /**
     * The expiry for a form's `mode` and `ends_on` (`YYYY-MM-DD`), read against `$now`, or null for a grant without
     * one. The caller has validated that `ends_on` is a date from today on.
     */
    public static function fromInput(string $mode, ?string $endsOn, CarbonInterface $now): ?self
    {
        if ($mode === 'none' || $endsOn === null) {
            return null;
        }

        $expiresAt = self::endOfZuluDay($endsOn);
        $today = CarbonImmutable::instance($now)->utc()->startOfDay();

        return new self($expiresAt, $mode === 'renew' ? max(1, (int) $today->diffInDays($expiresAt->startOfDay())) : null);
    }

    /** The last second of a Zulu day, as the grant's `expires_at`. */
    public static function endOfZuluDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->setTime(23, 59, 59);
    }
}
