<?php

namespace App\Commands\Counseling;

use App\Models\Trooper;
use App\Models\User;
use App\Repositories\TrooperRepository;

/**
 * Section 1 of a generated DCS or LOR: the rank, name (Last.F, the forum username) and billet of the trooper and of the
 * issuing authority, read from the synced roster. Never typed by the leader: the filing form shows it read-only, and
 * the PDF is always rendered from this. An issuer with no synced trooper row prints their username and nothing else.
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
