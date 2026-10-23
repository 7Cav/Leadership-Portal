<?php

namespace App\Repositories\AttendanceRepository;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\SectionWeekProgress;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements AttendanceRepository
{
    /**
     * The row is not an automatic makeup, so it can hold its practice (see countingMarks()). Only
     * ever applied to a row whose `status` is set; a null `source` (a mark that predates sources)
     * is spelled out, since `source = 'auto'` on it is unknown rather than false.
     */
    private static function notAutoMakeupSql(): string
    {
        $makeup = AttendanceStatus::Makeup->value;
        $auto = AttendanceSource::Auto->value;

        return "(attendance.status != '{$makeup}' or attendance.source is null or attendance.source != '{$auto}')";
    }

    public function markPractice(
        SectionPractice $practice,
        int $trooperId,
        AttendanceStatus $status,
        AttendanceSource $source,
    ): Attendance {
        return $this->upsert($practice, $trooperId, ['status' => $status, 'source' => $source]);
    }

    public function markPracticeAuto(SectionPractice $practice, int $trooperId): ?Attendance
    {
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];
        $values = ['status' => AttendanceStatus::Present, 'source' => AttendanceSource::Auto];

        return DB::transaction(function () use ($key, $values) {
            $row = Attendance::query()->where($key)->lockForUpdate()->first();

            if ($row === null) {
                try {
                    return $this->realise(Attendance::query()->create($key + $values));
                } catch (UniqueConstraintViolationException $e) {
                    // A racing writer inserted the row first; a locking read sees it (cf. upsert()).
                    $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
                }
            }

            if ($row->source === AttendanceSource::Manual || $row->status === AttendanceStatus::Loa) {
                return null;
            }

            $row->fill($values)->save();

            return $this->realise($row);
        });
    }

    public function markMakeupAuto(SectionPractice $practice, int $trooperId): ?Attendance
    {
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];
        $values = ['status' => AttendanceStatus::Makeup, 'source' => AttendanceSource::Auto];

        return DB::transaction(function () use ($key, $values) {
            $row = Attendance::query()->where($key)->lockForUpdate()->first();

            if ($row === null) {
                try {
                    return $this->realise(Attendance::query()->create($key + $values));
                } catch (UniqueConstraintViolationException $e) {
                    // A racing writer inserted the row first; a locking read sees it (cf. upsert()).
                    $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
                }
            }

            // A leader's say, the LOA mark, and an attended mark all stay: a makeup is only ever an
            // upgrade over nothing or over an automatic miss.
            $keeps = $row->source === AttendanceSource::Manual
                || in_array($row->status, [AttendanceStatus::Loa, AttendanceStatus::Present, AttendanceStatus::Attached], true);

            if ($keeps) {
                return null;
            }

            $row->fill($values)->save();

            return $this->realise($row);
        });
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

    public function countingMarks(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection
    {
        // A practice is held once any row on it carries a leader's SP mark. Signatures alone do
        // not hold it, and neither does `loa` — derived from LOA data, it says nothing about
        // whether the practice ran — nor an automatic makeup, which records a practice attended
        // elsewhere and is written whether or not this section practised. Only practices in the
        // window, of sections under `$unit`, can join below, so the subquery reads those and not
        // the whole attendance history.
        $from = WeekOf::requireStart($firstWeek);
        $to = WeekOf::requireStart($lastWeek);
        $sections = Unit::query()->select('id')->where('path', 'like', "{$unit->path}%");

        $held = Attendance::query()
            ->select('attendance.section_practice_id')
            ->join('section_practices', 'section_practices.id', '=', 'attendance.section_practice_id')
            ->whereBetween('section_practices.week_start', [$from, $to])
            ->whereIn('section_practices.section_id', $sections)
            ->whereNotNull('attendance.status')
            ->where('attendance.status', '!=', AttendanceStatus::Loa->value)
            ->whereRaw(self::notAutoMakeupSql())
            ->groupBy('attendance.section_practice_id');

        $population = $includeDeparted
            ? Trooper::query()->whereIn('troopers.primary_section_id', $sections)
            : Trooper::query()->assignedWithin($unit);

        return $population
            ->join('section_practices', 'section_practices.section_id', '=', 'troopers.primary_section_id')
            ->leftJoinSub($held, 'held', 'held.section_practice_id', '=', 'section_practices.id')
            ->leftJoin('attendance', function ($join) {
                $join->on('attendance.section_practice_id', '=', 'section_practices.id')
                    ->on('attendance.trooper_id', '=', 'troopers.id');
            })
            ->whereBetween('section_practices.week_start', [$from, $to])
            ->orderBy('troopers.id')
            ->orderBy('section_practices.week_start')
            ->toBase()
            ->get([
                'troopers.id as trooper_id',
                'section_practices.week_start',
                'attendance.status',
                'attendance.rollcall',
                'attendance.source',
                DB::raw('held.section_practice_id is not null as held'),
            ])
            ->map(fn (object $row) => new CountingMark(
                trooperId: (int) $row->trooper_id,
                weekStart: CarbonImmutable::parse($row->week_start, 'UTC'),
                status: $row->status === null ? null : AttendanceStatus::from($row->status),
                // Null only when the left join found no attendance row: nobody signed for them.
                rollcall: $row->rollcall === null ? RollcallSignature::Unsigned : RollcallSignature::from($row->rollcall),
                held: (bool) $row->held,
                source: $row->source === null ? null : AttendanceSource::from($row->source),
            ));
    }

    /**
     * @param  array<string, mixed>  $values  only the columns this write owns
     */
    public function weekProgress(array $sectionIds, CarbonInterface $weekStart, array $excusedTrooperIds = []): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if ($sectionIds === []) {
            return collect();
        }

        $unsigned = RollcallSignature::Unsigned->value;
        $loa = AttendanceStatus::Loa->value;
        $excused = array_values(array_unique(array_map('intval', $excusedTrooperIds)));
        // An excused trooper counts as signed, marked and on LOA whether or not they have a row, and never as unsigned;
        // `or` keeps one with a row from counting twice.
        $excusedSql = $excused === [] ? '' : ' or troopers.id in ('.implode(', ', array_fill(0, count($excused), '?')).')';
        $notExcusedSql = $excused === [] ? '' : ' and troopers.id not in ('.implode(', ', array_fill(0, count($excused), '?')).')';

        return Trooper::query()
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->whereIn('troopers.primary_section_id', $sectionIds)
            ->leftJoin('section_practices', function ($join) use ($weekStart) {
                $join->on('section_practices.section_id', '=', 'troopers.primary_section_id')
                    ->where('section_practices.week_start', '=', $weekStart);
            })
            ->leftJoin('attendance', function ($join) {
                $join->on('attendance.section_practice_id', '=', 'section_practices.id')
                    ->on('attendance.trooper_id', '=', 'troopers.id');
            })
            ->groupBy('troopers.primary_section_id')
            ->toBase()
            ->select('troopers.primary_section_id as section_id')
            ->selectRaw('count(troopers.id) as strength')
            ->selectRaw("coalesce(sum(case when (attendance.rollcall is not null and attendance.rollcall != '{$unsigned}'){$excusedSql} then 1 else 0 end), 0) as signed", $excused)
            ->selectRaw("coalesce(sum(case when attendance.status is not null{$excusedSql} then 1 else 0 end), 0) as marked", $excused)
            ->selectRaw("coalesce(sum(case when attendance.status is not null and attendance.status != '{$loa}' and ".self::notAutoMakeupSql().' then 1 else 0 end), 0) as held_marks')
            ->selectRaw("coalesce(sum(case when attendance.status = '{$loa}'{$excusedSql} then 1 else 0 end), 0) as on_loa", $excused)
            ->selectRaw("coalesce(sum(case when (attendance.status is null or attendance.status != '{$loa}') and (attendance.rollcall is null or attendance.rollcall = '{$unsigned}'){$notExcusedSql} then 1 else 0 end), 0) as not_signed", $excused)
            ->selectRaw('max(section_practices.rollcall_posted_at) as posted_at')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->section_id => new SectionWeekProgress(
                sectionId: (int) $row->section_id,
                strength: (int) $row->strength,
                signed: (int) $row->signed,
                marked: (int) $row->marked,
                held: (int) $row->held_marks > 0,
                onLoa: (int) $row->on_loa,
                unsigned: (int) $row->not_signed,
                postedAt: $row->posted_at === null ? null : CarbonImmutable::parse($row->posted_at, 'UTC'),
            )]);
    }

    private function upsert(SectionPractice $practice, int $trooperId, array $values): Attendance
    {
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];

        try {
            $row = Attendance::query()->updateOrCreate($key, $values);
        } catch (UniqueConstraintViolationException $e) {
            // A racing submit inserted the row after this transaction's snapshot: a plain re-read
            // cannot see it under MySQL REPEATABLE READ, a locking read can (see
            // SectionPracticeRepository's findOrCreate). Update it in place, last writer wins.
            $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
            $row->fill($values)->save();
        }

        return $this->realise($row);
    }

    /**
     * A real write over a generated demo row (#115) makes the row real, so a demo purge or
     * regeneration never deletes or overwrites what a leader recorded. `is_demo` is not
     * mass-assignable, hence the separate forced write.
     */
    private function realise(Attendance $row): Attendance
    {
        if ($row->is_demo) {
            $row->forceFill(['is_demo' => false])->save();
        }

        return $row;
    }
}
