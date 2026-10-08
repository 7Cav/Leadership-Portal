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
    public function record(
        SectionPractice $practice,
        int $trooperId,
        AttendanceSource $source,
        ?AttendanceStatus $status,
        RollcallSignature $rollcall,
    ): Attendance {
        return Attendance::query()->updateOrCreate(
            ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id],
            ['source' => $source, 'status' => $status, 'rollcall' => $rollcall],
        );
    }

    public function forPractice(SectionPractice $practice): Collection
    {
        return Attendance::query()
            ->with('trooper')
            ->where('section_practice_id', $practice->id)
            ->orderBy('id')
            ->get();
    }
}
