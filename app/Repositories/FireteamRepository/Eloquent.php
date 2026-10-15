<?php

namespace App\Repositories\FireteamRepository;

use App\Models\FireteamAssignment;
use App\Models\FireteamRole;
use App\Models\FireteamSetting;
use App\Repositories\FireteamRepository;
use Illuminate\Support\Collection;

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
        return FireteamAssignment::query()->updateOrCreate(
            ['section_id' => $sectionId, 'trooper_id' => $trooperId],
            ['role' => FireteamRole::Leader, 'leader_trooper_id' => null],
        );
    }

    public function makeMember(int $sectionId, int $trooperId, int $leaderTrooperId): FireteamAssignment
    {
        return FireteamAssignment::query()->updateOrCreate(
            ['section_id' => $sectionId, 'trooper_id' => $trooperId],
            ['role' => FireteamRole::Member, 'leader_trooper_id' => $leaderTrooperId],
        );
    }

    public function remove(int $sectionId, int $trooperId): ?FireteamAssignment
    {
        $row = FireteamAssignment::query()->where('section_id', $sectionId)->where('trooper_id', $trooperId)->first();

        if ($row === null) {
            return null;
        }

        if ($row->role === FireteamRole::Leader) {
            FireteamAssignment::query()->where('section_id', $sectionId)->where('leader_trooper_id', $trooperId)->delete();
        }

        $row->delete();

        return $row;
    }

    public function settingFor(int $unitId): ?FireteamSetting
    {
        return FireteamSetting::query()->where('unit_id', $unitId)->first();
    }

    public function updateSetting(int $unitId, array $values): FireteamSetting
    {
        $setting = FireteamSetting::query()->firstOrNew(['unit_id' => $unitId]);
        $setting->fill($values)->save();

        return $setting;
    }
}
