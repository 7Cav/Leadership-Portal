<?php

namespace App\Integration\Cav\Data;

use App\Integration\Cav\CavRequestException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

/**
 * A trooper's Cav-authoritative roster record (AD-5): rank, billet and MOS as api.7cav.us
 * reports them, for the full-roster read (story 2.1) rather than the single-trooper lookup.
 *
 * Deliberately distinct from {@see CavRosterProfile}: that DTO exists to resolve a *forum*
 * identity and throws when it cannot, but a roster sweep must not lose an otherwise-good
 * trooper record just because Cav has no forum account linked to it yet. Only `cavId` — the
 * milpac relation id, which is always the caller's map key, never the payload's — is strict
 * here. Every other field degrades to null on drift, per AC #6: a bad row costs a caller a
 * field, not the record.
 *
 * `tis`/`tig` are the *dates* Cav reports (`joinDate`/`promotionDate`) — points in time, not
 * durations. A caller wanting an actual time-in-service/time-in-grade span computes it from
 * these against the current date; that is a display concern, not this seam's.
 *
 * `positionId` is the primary billet's numeric id (2.5): Cav's API names a trooper's role, never
 * their section — `positionId` is the only stable key a portal-owned mapping (billet → section)
 * can be keyed on, since `billet`/`positionTitle` is free text a reorg could rename.
 */
readonly class CavRosterEntry
{
    public function __construct(
        public int $cavId,
        public DateTimeImmutable $fetchedAt,
        public ?string $rankShort = null,
        public ?string $rankFull = null,
        public ?string $mos = null,
        public ?string $billet = null,
        public ?int $positionId = null,
        public ?DateTimeImmutable $tis = null,
        public ?DateTimeImmutable $tig = null,
        public ?RosterType $status = null,
    ) {
        if ($cavId <= 0) {
            throw CavRequestException::malformedProfile("cav id {$cavId} is not a positive integer");
        }
    }

    /**
     * Build from a `Profile`/`LiteProfile` body. `$cavId` comes from the caller because the
     * milpac relation id is not part of the payload — it is the roster map key.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(int $cavId, array $payload, DateTimeImmutable $fetchedAt): self
    {
        // The API answers some failures with a 200 and a `{code, message, details}` body rather
        // than an error status. A genuine Profile always carries the `user` key — even when its
        // value is null for a trooper with no forum account linked — so its absence, not its
        // value, is what marks this as an error envelope.
        if (! array_key_exists('user', $payload)) {
            throw CavRequestException::malformedProfile('the response carried no `user` field, so it is likely an error envelope');
        }

        $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : [];
        $primary = is_array($payload['primary'] ?? null) ? $payload['primary'] : [];

        return new self(
            cavId: $cavId,
            fetchedAt: $fetchedAt,
            rankShort: self::text($rank['rankShort'] ?? null),
            rankFull: self::text($rank['rankFull'] ?? null),
            mos: self::text($payload['mos'] ?? null),
            billet: self::text($primary['positionTitle'] ?? null),
            positionId: self::integer($primary['positionId'] ?? null),
            tis: self::date($payload['joinDate'] ?? null),
            tig: self::date($payload['promotionDate'] ?? null),
            // An unrecognised roster type — including the API's explicit `*_UNSPECIFIED`
            // member, and any roster added later — carries no more than null does.
            status: RosterType::tryFrom((string) self::text($payload['roster'] ?? null)),
        );
    }

    /**
     * The API sends an unset string as `''`; carry that through as null so callers do not have
     * to distinguish "absent" from "empty".
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * `positionId` arrives as a numeric string; accepts a bare int too in case that ever changes,
     * since a JSON-encoding drift there must not silently stop every trooper from resolving a
     * section (2.5). A drift to something genuinely non-numeric costs this trooper their section
     * mapping, not their whole record.
     */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $text = self::text($value);

        return $text !== null && is_numeric($text) ? (int) $text : null;
    }

    /**
     * Lenient by design: a drift in the date format must cost this trooper their tis/tig, not
     * their whole roster record.
     */
    private static function date(mixed $value): ?DateTimeImmutable
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return new CarbonImmutable($text);
        } catch (Throwable) {
            return null;
        }
    }
}
