<?php

namespace App\Commands\Guests;

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Capture\CapturePracticesDue;
use App\Models\CrossSectionSighting;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\PracticeTiming;
use App\Repositories\SectionPracticeSlotRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The makeups of hand-added guests whose host practice is now over (#634). A guest added before the host's practice
 * hour ends is listed straight away, but their own section's roll call only gets the automatic makeup once that hour
 * has ended: the scheduler runs this every few minutes, as it runs {@see CapturePracticesDue}.
 *
 * Reads the hand-added guests of this Cav week and the last (a Friday 2330Z practice ends in the next week), skips a
 * host whose hour has not ended, and writes each guest's makeup through
 * {@see MarkAttendanceHandler::preMarkMakeups()}, the path an added guest and a capture take, with all its guards. Only
 * guests {@see MarkAttendanceHandler::makeupsMissing()} still lists are written, so a later run writes nothing again.
 * Audited as `attendance.auto_marked` with the leader who added the guest as the actor.
 */
class ApplyGuestMakeupsDue
{
    public function __construct(
        private readonly CrossSectionSightingRepository $sightings,
        private readonly SectionPracticeSlotRepository $slots,
        private readonly MarkAttendanceHandler $attendance,
    ) {}

    /** How many guests got their makeup at `$now`. */
    public function at(DateTimeInterface $now): int
    {
        $now = CarbonImmutable::instance($now)->utc();
        $thisWeek = WeekOf::start($now);
        $written = 0;

        foreach ($this->sightings->manualInWeeks([WeekOf::previous($thisWeek), $thisWeek])->groupBy('section_practice_id') as $rows) {
            $host = $rows->first()->practice;

            if ($host === null) {
                continue;
            }

            $end = PracticeTiming::hourEnd($host->week_start, $host, $this->slots->forSection($host->section_id));

            if ($end !== null && $end->greaterThan($now)) {
                continue;
            }

            $missing = $this->attendance->makeupsMissing($host, $rows->pluck('trooper_id')->map(fn ($id) => (int) $id)->values()->all());

            if ($missing === []) {
                continue;
            }

            // One write per leader who added them, so each makeup names whose word it was.
            $byLeader = $rows->filter(fn (CrossSectionSighting $row) => in_array((int) $row->trooper_id, $missing, true))
                ->groupBy(fn (CrossSectionSighting $row) => $row->recorded_by ?? 'system');

            foreach ($byLeader as $leader => $guests) {
                $result = $this->attendance->preMarkMakeups($host, $guests->pluck('trooper_id')->map(fn ($id) => (int) $id)->values()->all(), is_numeric($leader) ? (int) $leader : 'system');
                $written += $result->marked;
            }
        }

        return $written;
    }
}
