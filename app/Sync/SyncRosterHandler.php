<?php

namespace App\Sync;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\Data\CavRosterEntry;
use App\Integration\Cav\Data\RosterType;
use App\Models\TrooperStatus;
use App\Repositories\TrooperRepository;

/**
 * The sole writer of the `troopers` cache (AD-4, AD-22). `CavApi::fetchRoster()` is called
 * first, in full, before any write — a thrown `CavRequestException` (2.1) therefore aborts here
 * with the cache completely untouched, never a partial wipe. Recording that failure is Story
 * 2.3's job; this handler only needs to guarantee the abort is clean.
 */
class SyncRosterHandler
{
    public function __construct(
        private readonly CavApi $cavApi,
        private readonly TrooperRepository $troopers,
    ) {}

    public function handle(): void
    {
        $result = $this->cavApi->fetchRoster();

        $rows = array_map(
            fn (CavRosterEntry $entry) => [
                'cav_id' => $entry->cavId,
                'rank' => $entry->rankFull,
                'mos' => $entry->mos,
                'tis' => $entry->tis,
                'tig' => $entry->tig,
                'status' => $this->statusFor($entry->status)->value,
            ],
            $result->troopers,
        );

        $this->troopers->upsertActive($rows);
        $this->troopers->markDepartedExcept(array_column($rows, 'cav_id'));
    }

    /**
     * A roster type Cav didn't report (unrecognised or absent) carries no more standing than
     * departed does — {@see CavRosterEntry} already reduces the API's own `*_UNSPECIFIED` case
     * to null, so this is the only fallback this mapping needs.
     */
    private function statusFor(?RosterType $rosterType): TrooperStatus
    {
        return match ($rosterType) {
            RosterType::Combat => TrooperStatus::Combat,
            RosterType::Reserve => TrooperStatus::Reserve,
            RosterType::Eloa => TrooperStatus::Eloa,
            default => TrooperStatus::Inactive,
        };
    }
}
