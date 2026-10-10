<?php

namespace App\Rollcall;

use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Loa\LoaProvenance;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the rollcall screen shows for one section and one Cav week (Story 3.7): the section's roster, each trooper's
 * attendance for the week, and what it was pre-filled from. Read-only, and it never decides who may look: the caller
 * gates the section with the ScopeResolver (AD-8) before asking.
 *
 * Rows are the active troopers whose primary section is this section (AD-14): a trooper holding a billet elsewhere is
 * listed here and nowhere else. Departed troopers are left out.
 *
 * Each row's practice value comes from the first of these that applies:
 *  - `loa`, derived from the leave data when a cleanly read leave covers the week (3.4). It is never stored, and the
 *    row's toggle is replaced by the badge. A leave that needs review derives nothing and the row stays ordinary.
 *  - the mark already saved for the week (`saved`), with who made it.
 *  - `present`, carried from the prior week when the trooper was present then and nothing is saved yet (`carried`).
 *    Only `present` carries: a missed, makeup or attached mark never starts a new week as a default, so nobody is
 *    pre-marked absent without a leader looking. Carrying nothing is a valid answer, and the banner says so.
 *  - nothing: the row loads unmarked.
 * A carried value is a suggestion shown on the screen, not a stored mark; it becomes one only when a leader submits.
 *
 * `template` is the BBCode template the section's company has active, resolved on every request (Story 3.6), with its
 * version id; the browser renders the BBCode from it as the leader marks (3.10). Every synced value is returned with its
 * source and last-sync time (AD-20).
 */
final class RollcallScreen
{
    /** The mark that carries forward from one week to the next as a default. */
    private const CARRIED_STATUS = AttendanceStatus::Present;

    public function __construct(
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly LoaCoverage $loaCoverage,
        private readonly LoaProvenance $loaProvenance,
        private readonly SyncRunRepository $syncRuns,
        private readonly TemplateResolver $templates,
    ) {}

    /**
     * @param  CarbonImmutable  $weekStart  a Saturday 00:00 UTC, as {@see WeekOf::start()} gives
     * @return array<string, mixed>
     */
    public function for(Unit $section, CarbonImmutable $weekStart): array
    {
        $roster = $this->troopers->placedWithin($section)
            ->reject(fn (Trooper $trooper) => $trooper->status === TrooperStatus::Inactive)
            ->sortBy([['display_name', 'asc'], ['id', 'asc']])
            ->values();

        $covers = $this->loaCoverage->forTroopers($roster->pluck('id')->all(), $weekStart);
        $saved = $this->marksOf($section, $weekStart);
        $prior = $this->marksOf($section, $weekStart->subWeek());
        $hasPriorWeek = $this->practices->find($section->id, $weekStart->subWeek()) !== null;

        $rows = $roster->map(fn (Trooper $trooper) => $this->row($trooper, $covers[$trooper->id], $saved->get($trooper->id), $prior->get($trooper->id)))->all();

        $eligible = count(array_filter($rows, fn (array $row) => $row['practice']['origin'] !== 'leave'));
        $carried = count(array_filter($rows, fn (array $row) => $row['practice']['origin'] === 'carried'));
        $savedCount = count(array_filter($rows, fn (array $row) => $row['practice']['origin'] === 'saved'));

        return [
            'section' => ['id' => $section->id, 'name' => $section->name, 'callsign' => $section->callsign],
            'week' => $this->week($weekStart),
            'template' => $this->templates->forUnit($section)->toArray(),
            'troopers' => $rows,
            'banner' => [
                'eligible' => $eligible,
                'saved' => $savedCount,
                'carried' => $carried,
                'fromWeek' => $hasPriorWeek ? $weekStart->subWeek()->toDateString() : null,
            ],
            'sync' => [
                'roster' => $this->rosterSync(),
                'loa' => $this->loaProvenance->current()->toArray(),
            ],
        ];
    }

    /**
     * @return Collection<int, Attendance> the week's rows keyed by trooper id, none when the section has no practice yet
     */
    private function marksOf(Unit $section, CarbonImmutable $weekStart): Collection
    {
        $practice = $this->practices->find($section->id, $weekStart);

        return $practice === null ? collect() : $this->attendance->forPractice($practice)->keyBy('trooper_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Trooper $trooper, LoaCover $cover, ?Attendance $saved, ?Attendance $prior): array
    {
        $derived = $cover->derivedAttendanceStatus();

        if ($derived !== null) {
            $practice = ['value' => $derived->value, 'origin' => 'leave', 'source' => null];
        } elseif ($saved?->status !== null) {
            $practice = ['value' => $saved->status->value, 'origin' => 'saved', 'source' => $saved->source?->value];
        } elseif ($prior?->status === self::CARRIED_STATUS) {
            $practice = ['value' => self::CARRIED_STATUS->value, 'origin' => 'carried', 'source' => null];
        } else {
            $practice = ['value' => null, 'origin' => null, 'source' => null];
        }

        return [
            'id' => $trooper->id,
            'displayName' => $trooper->display_name,
            'username' => $trooper->username,
            'rank' => $trooper->rank,
            'rankShort' => $trooper->rank_short,
            'billet' => $trooper->billet,
            'status' => $trooper->status->value,
            'practice' => $practice,
            'rollcall' => ($saved?->rollcall ?? RollcallSignature::Unsigned)->value,
            'loa' => $cover->toArray(),
        ];
    }

    /**
     * @return array{start: string, end: string, isCurrent: bool, previous: string, next: ?string}
     */
    private function week(CarbonImmutable $weekStart): array
    {
        $current = WeekOf::start(CarbonImmutable::now());

        return [
            'start' => $weekStart->toDateString(),
            'end' => $weekStart->addDays(6)->toDateString(),
            'isCurrent' => $weekStart->equalTo($current),
            'previous' => $weekStart->subWeek()->toDateString(),
            // A future week is never offered (the controller refuses one too).
            'next' => $weekStart->lessThan($current) ? $weekStart->addWeek()->toDateString() : null,
        ];
    }

    /**
     * Where the roster values came from and how current they are (AD-20, AD-4): the last successful roster sync, and a
     * stale flag when a later run did not succeed.
     *
     * @return array{source: string, lastSyncedAt: ?string, stale: bool}
     */
    private function rosterSync(): array
    {
        $success = $this->syncRuns->latestSuccessful('roster');
        $latest = $this->syncRuns->latestFinished('roster');

        return [
            'source' => 'Cav roster',
            'lastSyncedAt' => $success?->finished_at?->toIso8601String(),
            'stale' => $latest !== null && $latest->id !== $success?->id,
        ];
    }
}
