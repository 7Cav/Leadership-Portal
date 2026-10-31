<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\TrooperRepository;

/**
 * Section 1 of a DCS or LOR: the rank, name (Last.F, the forum username) and billet of the trooper and of the issuing
 * authority, read from the synced roster. Never typed by the leader: a draft shows it live, share snapshots it
 * ({@see forRecord()}), and the form is always filled from this. An issuer with no synced trooper row prints their
 * username and nothing else.
 */
class CounselingFormParties
{
    public function __construct(private readonly TrooperRepository $troopers) {}

    /**
     * @return array{trooper: array{rank: string, name: string, billet: string}, authority: array{rank: string, name: string, billet: string}}
     */
    public function for(Trooper $trooper, User $issuer): array
    {
        $own = $issuer->cav_id === null ? null : $this->troopers->byCavId([(int) $issuer->cav_id])->first();

        return [
            'trooper' => self::party($trooper, (string) $trooper->username),
            'authority' => self::party($own, (string) $issuer->username),
        ];
    }

    /**
     * One person's rank, Last.F and billet as they stand now: the appeal authority snapshotted into the decision (#565).
     *
     * @return array{rank: string, name: string, billet: string}
     */
    public function of(User $user): array
    {
        $own = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->first();

        return self::party($own, (string) $user->username);
    }

    /**
     * Section 1 of a record: as snapshotted when it was shared, or, on a draft, as it stands now, with its issuer as the
     * authority. The Date of Letter is the record's date.
     *
     * @return array{date: ?string, trooper: array{rank: string, name: string, billet: string}, authority: array{rank: string, name: string, billet: string}}
     */
    public function forRecord(CounselingRecord $record): array
    {
        if (is_array($record->snapshot) && isset($record->snapshot['trooper'], $record->snapshot['authority'])) {
            return [
                'date' => $record->snapshot['date'] ?? $record->counseled_on?->toDateString(),
                'trooper' => $record->snapshot['trooper'],
                'authority' => $record->snapshot['authority'],
            ];
        }

        $parties = $record->issuedBy !== null
            ? $this->for($record->trooper, $record->issuedBy)
            : ['trooper' => self::party($record->trooper, (string) $record->trooper->username), 'authority' => self::party(null, '')];

        return ['date' => $record->counseled_on?->toDateString(), ...$parties];
    }

    /**
     * @return array{rank: string, name: string, billet: string}
     */
    private static function party(?Trooper $trooper, string $fallbackName): array
    {
        $name = $trooper !== null && filled($trooper->username) ? (string) $trooper->username : $fallbackName;

        return [
            'rank' => (string) ($trooper?->rank_short ?? ''),
            'name' => $name !== '' ? $name : ($trooper !== null ? "#{$trooper->cav_id}" : ''),
            'billet' => (string) ($trooper?->billet ?? ''),
        ];
    }
}
