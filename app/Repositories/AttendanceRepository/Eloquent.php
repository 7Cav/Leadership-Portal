<?php

namespace App\Repositories\AttendanceRepository;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Repositories\AttendanceRepository;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];

        try {
            return Attendance::query()->updateOrCreate($key, $values);
        } catch (UniqueConstraintViolationException $e) {
            // A racing submit inserted the row after this transaction's snapshot: a plain re-read
            // cannot see it under MySQL REPEATABLE READ, a locking read can (see
            // SectionPracticeRepository's findOrCreate). Update it in place, last writer wins.
            $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
            $row->fill($values)->save();

            return $row;
        }
    }
}
