<?php

namespace App\Repositories\AttendanceRepository;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Repositories\AttendanceRepository;
use Illuminate\Support\Collection;

class Eloquent implements AttendanceRepository
{
    public function markPractice(
        SectionPractice $practice,
        int $trooperId,
        AttendanceStatus $status,
        AttendanceSource $source,
    ): Attendance {
        return $this->upsert($practice, $trooperId, ['status' => $status, 'source' => $source]);
    }

    public function signRollcall(
        SectionPractice $practice,
        int $trooperId,
        RollcallSignature $rollcall,
    ): Attendance {
        return $this->upsert($practice, $trooperId, ['rollcall' => $rollcall]);
    }

    public function forPractice(SectionPractice $practice): Collection
    {
        return Attendance::query()
            ->with('trooper')
            ->where('section_practice_id', $practice->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $values  only the columns this write owns
     */
    private function upsert(SectionPractice $practice, int $trooperId, array $values): Attendance
    {
        return Attendance::query()->updateOrCreate(
            ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id],
            $values,
        );
    }
}
