<?php

namespace App\Repositories\FireteamRepository;

use App\Models\FireteamAssignment;
use App\Models\FireteamRole;
use App\Models\FireteamSetting;
use App\Repositories\FireteamRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every write is an upsert on the table's UNIQUE key followed by a lock on the row it landed on, never a read and
 * then an insert: two first writes racing past an "is there a row?" read would both insert, and the loser's
 * duplicate-key error (or, on MySQL, the deadlock of two gap locks both turning into inserts) would end the request
 * in a 500. An upsert takes an exclusive lock on the row whether it inserts or hits the duplicate, so concurrent
 * writers of one key queue one at a time — the same pattern as `WeeklyReportRepository`. The row is then read back
 * with a locking read, which sees the latest committed version even inside an older REPEATABLE READ snapshot.
 */
class Eloquent implements FireteamRepository
{
    public function assignmentsIn(int $sectionId): Collection
    {
        return FireteamAssignment::query()
            ->where('section_id', $sectionId)
            ->orderBy('id')
            ->get()
            ->keyBy('trooper_id');
    }

    public function makeLeader(int $sectionId, int $trooperId): FireteamAssignment
    {
        return $this->writeAssignment($sectionId, $trooperId, FireteamRole::Leader, null);
    }

    public function makeMember(int $sectionId, int $trooperId, int $leaderTrooperId): FireteamAssignment
    {
        return $this->writeAssignment($sectionId, $trooperId, FireteamRole::Member, $leaderTrooperId);
    }

    /**
     * Reads the row under a lock, so of two removes racing for the same trooper the second waits for the first and
     * then finds nothing: a null, never a second delete of a row that is already gone.
     */
    public function remove(int $sectionId, int $trooperId): ?FireteamAssignment
    {
        return DB::transaction(function () use ($sectionId, $trooperId) {
            $row = FireteamAssignment::query()->where('section_id', $sectionId)->where('trooper_id', $trooperId)->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            if ($row->role === FireteamRole::Leader) {
                FireteamAssignment::query()->where('section_id', $sectionId)->where('leader_trooper_id', $trooperId)->delete();
            }

            $row->delete();

            return $row;
        });
    }

    public function settingFor(int $unitId): ?FireteamSetting
    {
        return FireteamSetting::query()->where('unit_id', $unitId)->first();
    }

    /**
     * Upserts an empty row on the UNIQUE `unit_id` key first (every choice is nullable, so an empty row means "nothing
     * set"), locks it, and only then writes the given keys over it, so the keys left out keep whatever an earlier or
     * concurrent write gave them.
     */
    public function updateSetting(int $unitId, array $values): FireteamSetting
    {
        return DB::transaction(function () use ($unitId, $values) {
            FireteamSetting::query()->upsert([['unit_id' => $unitId]], ['unit_id'], []);

            $setting = FireteamSetting::query()->where('unit_id', $unitId)->lockForUpdate()->sole();
            $setting->fill($values)->save();

            return $setting;
        });
    }

    private function writeAssignment(int $sectionId, int $trooperId, FireteamRole $role, ?int $leaderTrooperId): FireteamAssignment
    {
        return DB::transaction(function () use ($sectionId, $trooperId, $role, $leaderTrooperId) {
            FireteamAssignment::query()->upsert(
                [['section_id' => $sectionId, 'trooper_id' => $trooperId, 'role' => $role->value, 'leader_trooper_id' => $leaderTrooperId]],
                ['section_id', 'trooper_id'],
                ['role', 'leader_trooper_id'],
            );

            return FireteamAssignment::query()->where('section_id', $sectionId)->where('trooper_id', $trooperId)->lockForUpdate()->sole();
        });
    }
}
