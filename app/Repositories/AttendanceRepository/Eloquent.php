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
use App\Repositories\CountingWindow;
use App\Repositories\PlacementWeeks;
use App\Repositories\SectionWeekProgress;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements AttendanceRepository
{
    public function __construct(
        private readonly TrooperPlacementRepository $placements,
    ) {}

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

    public function clearMakeupAuto(SectionPractice $practice, int $trooperId): ?Attendance
    {
        return DB::transaction(function () use ($practice, $trooperId) {
            $row = Attendance::query()
                ->where(['trooper_id' => $trooperId, 'section_practice_id' => $practice->id])
                ->lockForUpdate()
                ->first();

            // Only the automatic makeup itself: a leader's word, an attended mark and every other status stay.
            if ($row === null || $row->status !== AttendanceStatus::Makeup || $row->source !== AttendanceSource::Auto) {
                return null;
            }

            $row->fill(['status' => null, 'source' => null])->save();

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
        return $this->countingWindow($unit, $firstWeek, $lastWeek, $includeDeparted)->marks;
    }

    public function countingWindow(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): CountingWindow
    {
        $population = $includeDeparted
            ? Trooper::query()->whereIn('troopers.primary_section_id', Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"))
            : Trooper::query()->assignedWithin($unit);

        return $this->countingMarksFor($population, $firstWeek, $lastWeek);
    }

    public function countingWindowAsPlaced(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): CountingWindow
    {
        $from = WeekOf::requireStart($firstWeek);
        $to = WeekOf::requireStart($lastWeek);
        $placements = $this->placements->sectionsByWeekWithin($unit, $from, $to);
        $week = $to->toDateString();
        $sections = [];

        foreach (array_keys($placements->current) as $trooperId) {
            $sections[$trooperId] = $placements->sectionIn($trooperId, $week);
        }

        return $this->countingMarksFrom($placements, $from, $to, $sections);
    }

    public function countingMarksOf(array $trooperIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection
    {
        WeekOf::requireStart($firstWeek);
        WeekOf::requireStart($lastWeek);
        $trooperIds = array_values(array_unique(array_map('intval', $trooperIds)));

        if ($trooperIds === []) {
            return collect();
        }

        $population = Trooper::query()
            ->whereIn('troopers.id', $trooperIds)
            ->when(! $includeDeparted, fn (Builder $query) => $query->whereIn('troopers.status', TrooperStatus::servingValues()));

        return $this->countingMarksFor($population, $firstWeek, $lastWeek)->marks;
    }

    /**
     * The one counting-marks read behind {@see countingMarks()}, {@see CountingWindow()} and {@see countingMarksOf()}:
     * each trooper of `$population`, week by week, against the practice of the section they were placed in that week
     * (`TrooperPlacementRepository::sectionsByWeek`, 2026-10-27), so their marks follow them through a transfer and a
     * new section's practices from before they joined are never theirs. Two queries however large the population:
     * where the troopers were placed, then those sections' practices in the window with their held flag and the
     * population's rows on them.
     *
     * In the week of a move the trooper was placed in two sections. Still one counting mark (AD-14): the practice they
     * attended, else the one they were marked at, else the one they were placed in last. A rollcall they signed at
     * either section counts as signed.
     *
     * @param  Builder<Trooper>  $population
     */
    private function countingMarksFor(Builder $population, CarbonInterface $firstWeek, CarbonInterface $lastWeek): CountingWindow
    {
        $from = WeekOf::requireStart($firstWeek);
        $to = WeekOf::requireStart($lastWeek);
        $placements = $this->placements->sectionsByWeek($population, $from, $to);

        return $this->countingMarksFrom($placements, $from, $to, $placements->current);
    }

    /**
     * The marks half of {@see countingMarksFor()}, for placements already read.
     *
     * @param  array<int, int>  $countIn  trooper id => the section they count in, for the window's `sections`
     */
    private function countingMarksFrom(PlacementWeeks $placements, CarbonImmutable $from, CarbonImmutable $to, array $countIn): CountingWindow
    {
        $sectionIds = $placements->sectionIds();

        if ($sectionIds === []) {
            return new CountingWindow(collect(), $countIn, $placements);
        }

        // A practice is held once any row on it carries a leader's SP mark. Signatures alone do
        // not hold it, and neither does `loa` (derived from LOA data, it says nothing about
        // whether the practice ran) nor an automatic makeup, which records a practice attended
        // elsewhere and is written whether or not this section practised. Only practices in the
        // window, of the sections the population was placed in, are read.
        $held = Attendance::query()
            ->select('attendance.section_practice_id')
            ->join('section_practices', 'section_practices.id', '=', 'attendance.section_practice_id')
            ->whereBetween('section_practices.week_start', [$from, $to])
            ->whereIn('section_practices.section_id', $sectionIds)
            ->whereNotNull('attendance.status')
            ->where('attendance.status', '!=', AttendanceStatus::Loa->value)
            ->whereRaw(self::notAutoMakeupSql())
            ->groupBy('attendance.section_practice_id');

        $trooperIds = array_keys($placements->weeks);

        // Each practice once with no row, or once per row of the population on it.
        $found = SectionPractice::query()
            ->whereIn('section_practices.section_id', $sectionIds)
            ->whereBetween('section_practices.week_start', [$from, $to])
            ->leftJoinSub($held, 'held', 'held.section_practice_id', '=', 'section_practices.id')
            ->leftJoin('attendance', function ($join) use ($trooperIds) {
                $join->on('attendance.section_practice_id', '=', 'section_practices.id')
                    ->whereIn('attendance.trooper_id', $trooperIds);
            })
            ->toBase()
            ->get([
                'section_practices.id',
                'section_practices.section_id',
                'section_practices.week_start',
                DB::raw('held.section_practice_id is not null as held'),
                'attendance.trooper_id',
                'attendance.status',
                'attendance.rollcall',
                'attendance.source',
            ]);

        $practices = $found->keyBy(fn (object $row) => $row->section_id.'|'.CarbonImmutable::parse($row->week_start, 'UTC')->toDateString());
        $rows = $found->whereNotNull('trooper_id')->keyBy(fn (object $row) => $row->trooper_id.'|'.$row->id);

        $statusOf = fn (array $option) => $option['row']?->status === null ? null : AttendanceStatus::from($option['row']->status);
        $signed = fn (array $option) => $option['row'] !== null && $option['row']->rollcall !== null && $option['row']->rollcall !== RollcallSignature::Unsigned->value;
        $marks = [];

        foreach ($placements->weeks as $trooperId => $weeks) {
            ksort($weeks);

            foreach ($weeks as $week => $sections) {
                $options = collect($sections)
                    ->map(fn (int $sectionId) => $practices->get($sectionId.'|'.$week))
                    ->filter()
                    ->map(fn (object $practice) => ['practice' => $practice, 'row' => $rows->get($trooperId.'|'.$practice->id)])
                    ->values();

                // No practice row that week at any section they were in: no counting mark, as before.
                if ($options->isEmpty()) {
                    continue;
                }

                $chosen = $options->last(fn (array $option) => in_array($statusOf($option), [AttendanceStatus::Present, AttendanceStatus::Makeup, AttendanceStatus::Attached], true))
                    ?? $options->last(fn (array $option) => $statusOf($option) !== null)
                    ?? $options->last();
                $signedAt = $signed($chosen) ? $chosen : $options->first($signed);
                $row = $chosen['row'];

                $marks[] = new CountingMark(
                    trooperId: (int) $trooperId,
                    weekStart: CarbonImmutable::parse($week, 'UTC'),
                    status: $statusOf($chosen),
                    // Unsigned when they have no row, or signed at none of the week's practices.
                    rollcall: $signedAt === null ? RollcallSignature::Unsigned : RollcallSignature::from($signedAt['row']->rollcall),
                    held: (bool) $chosen['practice']->held,
                    source: $row?->source === null ? null : AttendanceSource::from($row->source),
                    sectionId: (int) $chosen['practice']->section_id,
                );
            }
        }

        return new CountingWindow(collect($marks), $countIn, $placements);
    }

    public function weekProgress(array $sectionIds, CarbonInterface $weekStart, array $excusedTrooperIds = [], ?array $roster = null): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if ($sectionIds === []) {
            return collect();
        }

        if ($roster !== null) {
            return $this->progressOf($sectionIds, $weekStart, $excusedTrooperIds, $roster);
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

    /**
     * {@see weekProgress()} over a given roster (a week that has ended): the same counts, read from each section's
     * practice and its rows for the roster's troopers in one query, and added up here.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $excusedTrooperIds
     * @param  array<int, int>  $roster  trooper id => the section whose rollcall they are on
     * @return Collection<int, SectionWeekProgress>
     */
    private function progressOf(array $sectionIds, CarbonImmutable $weekStart, array $excusedTrooperIds, array $roster): Collection
    {
        $wanted = array_flip(array_map('intval', $sectionIds));
        $bySection = [];

        foreach ($roster as $trooperId => $sectionId) {
            if (isset($wanted[$sectionId])) {
                $bySection[$sectionId][] = (int) $trooperId;
            }
        }

        if ($bySection === []) {
            return collect();
        }

        $trooperIds = array_merge(...array_values($bySection));
        $rows = SectionPractice::query()
            ->toBase()
            ->whereIn('section_practices.section_id', array_keys($bySection))
            ->where('section_practices.week_start', '=', $weekStart)
            ->leftJoin('attendance', function ($join) use ($trooperIds) {
                $join->on('attendance.section_practice_id', '=', 'section_practices.id')
                    ->whereIn('attendance.trooper_id', $trooperIds);
            })
            ->get(['section_practices.section_id', 'section_practices.rollcall_posted_at', 'attendance.trooper_id', 'attendance.rollcall', 'attendance.status', 'attendance.source']);

        $posted = [];
        $marks = [];

        foreach ($rows as $row) {
            $sectionId = (int) $row->section_id;

            if ($row->rollcall_posted_at !== null) {
                $posted[$sectionId] = max($posted[$sectionId] ?? '', (string) $row->rollcall_posted_at);
            }

            // Only the row on the practice of the section whose rollcall lists them, as the query above joins it.
            if ($row->trooper_id !== null && ($roster[(int) $row->trooper_id] ?? null) === $sectionId) {
                $marks[(int) $row->trooper_id] = $row;
            }
        }

        $excused = array_flip(array_map('intval', $excusedTrooperIds));
        $unsigned = RollcallSignature::Unsigned->value;
        $loa = AttendanceStatus::Loa->value;
        $makeup = AttendanceStatus::Makeup->value;
        $auto = AttendanceSource::Auto->value;
        $progress = [];

        ksort($bySection);

        foreach ($bySection as $sectionId => $ids) {
            $counts = ['signed' => 0, 'marked' => 0, 'held' => false, 'onLoa' => 0, 'unsigned' => 0];

            foreach ($ids as $trooperId) {
                $row = $marks[$trooperId] ?? null;
                $isExcused = isset($excused[$trooperId]);
                $rollcall = $row?->rollcall;
                $status = $row?->status;

                $counts['signed'] += ($rollcall !== null && $rollcall !== $unsigned) || $isExcused ? 1 : 0;
                $counts['marked'] += $status !== null || $isExcused ? 1 : 0;
                // Held by a leader's mark: never by LOA, and never by an automatic makeup.
                $counts['held'] = $counts['held'] || ($status !== null && $status !== $loa && ! ($status === $makeup && $row->source === $auto));
                $counts['onLoa'] += $status === $loa || $isExcused ? 1 : 0;
                $counts['unsigned'] += ! $isExcused && $status !== $loa && ($rollcall === null || $rollcall === $unsigned) ? 1 : 0;
            }

            $progress[$sectionId] = new SectionWeekProgress(
                sectionId: $sectionId,
                strength: count($ids),
                signed: $counts['signed'],
                marked: $counts['marked'],
                held: $counts['held'],
                onLoa: $counts['onLoa'],
                unsigned: $counts['unsigned'],
                postedAt: isset($posted[$sectionId]) ? CarbonImmutable::parse($posted[$sectionId], 'UTC') : null,
            );
        }

        return collect($progress);
    }

    /**
     * @param  array<string, mixed>  $values  only the columns this write owns
     */
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
