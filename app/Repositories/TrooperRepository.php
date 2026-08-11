<?php

namespace App\Repositories;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use Illuminate\Support\Facades\DB;

/**
 * Sole DB access for the cached roster (AD-2, AD-4). `Sync\SyncRosterHandler` is the only caller
 * of the writes here — this class enforces the upsert/never-delete shape AD-4 requires, but it
 * is the handler's job to decide *which* rows are active.
 */
class TrooperRepository
{
    /**
     * Upsert the active rows and mark everyone else departed as one transaction, so a crash
     * between the two halves can never leave the cache in a state where some troopers were
     * refreshed but departures were not yet applied (or vice versa) — a torn write the sync
     * handler's own "no partial wipe" contract would otherwise only hold for a *fetch* failure,
     * not a write-time one. Returns the row counts the sync handler reports to `sync_runs`
     * (Story 2.3).
     *
     * @param  list<array{cav_id: int, rank: ?string, mos: ?string, tis: mixed, tig: mixed, status: string}>  $rows
     * @param  list<int>  $activeCavIds
     * @return array{inserted: int, updated: int, inactivated: int}
     */
    public function sync(array $rows, array $activeCavIds): array
    {
        return DB::transaction(function () use ($rows, $activeCavIds) {
            $upserted = $this->upsertActive($rows);
            $inactivated = $this->markDepartedExcept($activeCavIds);

            return [...$upserted, 'inactivated' => $inactivated];
        });
    }

    /**
     * Upsert every row keyed by `cav_id`, updating an existing trooper's synced columns in place
     * (their internal `id` PK never changes) or inserting a new one. Never touches
     * `primary_section_id` — that column belongs to 2.5/2.7, not the sync handler.
     *
     * @param  list<array{cav_id: int, rank: ?string, mos: ?string, tis: mixed, tig: mixed, status: string}>  $rows
     * @return array{inserted: int, updated: int}
     */
    public function upsertActive(array $rows): array
    {
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $trooper = Trooper::updateOrCreate(
                ['cav_id' => $row['cav_id']],
                [
                    'rank' => $row['rank'],
                    'mos' => $row['mos'],
                    'tis' => $row['tis'],
                    'tig' => $row['tig'],
                    'status' => $row['status'],
                ],
            );

            $trooper->wasRecentlyCreated ? $inserted++ : $updated++;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Flip every cached trooper whose `cav_id` is not in `$activeCavIds` to inactive — retained,
     * never deleted, so dependent `attendance`/`weekly_reports` history survives a roster
     * departure (AD-4, AD-5). Already-inactive rows are left alone rather than re-touched.
     * Returns the number of rows actually flipped.
     *
     * @param  list<int>  $activeCavIds
     */
    public function markDepartedExcept(array $activeCavIds): int
    {
        return Trooper::query()
            ->whereNotIn('cav_id', $activeCavIds)
            ->where('status', '!=', TrooperStatus::Inactive->value)
            ->update(['status' => TrooperStatus::Inactive->value]);
    }
}
