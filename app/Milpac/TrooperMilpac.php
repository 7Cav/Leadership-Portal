<?php

namespace App\Milpac;

use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperStatus;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperCourseRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the trooper detail page (the milpac) shows for one trooper (Stories 6.3, 6.4): the ID block and the
 * courses and qualifications panel, read through the repositories and shaped as page props. Read-only, and it
 * never decides who may look: the caller gates that with the ScopeResolver (AD-8) before asking.
 *
 * The ID block is the cached Cav roster, so the page can show where it came from and how fresh it is (AD-20).
 * Durations are counted on the server's UTC day (AD-11) so every viewer reads the same figure.
 */
final class TrooperMilpac
{
    /** The Cav milpac page for a trooper, keyed by their milpac id. */
    public const MILPAC_PROFILE_URL = 'https://7cav.us/rosters/profile?uniqueid=';

    /** The order the course groups are shown in. `none` is left out: it means the trooper has nothing on the course. */
    private const GROUPS = [CourseStatus::Qualified, CourseStatus::InProgress, CourseStatus::Required];

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * @return array{trooper: array<string, mixed>, courses: array<string, mixed>, sync: array<string, mixed>}
     */
    public function for(Trooper $trooper): array
    {
        return [
            'trooper' => $this->idBlock($trooper),
            'courses' => $this->courses($trooper),
            'sync' => $this->sync(),
        ];
    }

    /**
     * The ID block. A trooper the sync could not place has no section, and says so with `section: null`
     * rather than an error; a trooper who has left every roster still renders, as departed, from their
     * last cached identity.
     *
     * @return array<string, mixed>
     */
    private function idBlock(Trooper $trooper): array
    {
        $section = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);
        $today = CarbonImmutable::now('UTC');

        return [
            'id' => $trooper->id,
            'cavId' => $trooper->cav_id,
            'displayName' => $trooper->display_name,
            'username' => $trooper->username,
            'rank' => $trooper->rank,
            'rankShort' => $trooper->rank_short,
            'billet' => $trooper->billet,
            'mos' => $trooper->mos,
            'status' => $trooper->status->value,
            'departed' => $trooper->status === TrooperStatus::Inactive,
            'tis' => $trooper->tis?->toDateString(),
            'tisMonths' => $this->monthsSince($trooper->tis?->toDateString(), $today),
            'tig' => $trooper->tig?->toDateString(),
            'tigMonths' => $this->monthsSince($trooper->tig?->toDateString(), $today),
            'section' => $section === null ? null : ['id' => $section->id, 'name' => $section->name, 'callsign' => $section->callsign],
            'milpacUrl' => self::MILPAC_PROFILE_URL.$trooper->cav_id,
        ];
    }

    /**
     * Whole months from a date to today, or null when the date is unknown or is in the future (a bad sync
     * value is not shown as a negative time in service).
     */
    private function monthsSince(?string $date, CarbonImmutable $today): ?int
    {
        if ($date === null) {
            return null;
        }

        $interval = CarbonImmutable::parse($date, 'UTC')->diff($today);

        return $interval->invert === 1 ? null : $interval->y * 12 + $interval->m;
    }

    /**
     * The courses panel: the trooper's courses grouped as qualified, in progress and required, each group in
     * the catalog's order. `none` rows and absent rows are the same thing and are not listed, and a group
     * with nothing in it is not returned.
     *
     * @return array{groups: list<array<string, mixed>>, qualified: int, total: int}
     */
    private function courses(Trooper $trooper): array
    {
        /** @var Collection<int, TrooperCourse> $rows */
        $rows = $this->trooperCourses->forTrooper($trooper->id)->reject(fn (TrooperCourse $row) => $row->status === CourseStatus::None);

        $groups = collect(self::GROUPS)
            ->map(fn (CourseStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'courses' => $rows
                    ->filter(fn (TrooperCourse $row) => $row->status === $status)
                    ->map(fn (TrooperCourse $row) => [
                        'code' => $row->course->code,
                        'name' => $row->course->name,
                        'awardedAt' => $row->awarded_at?->toDateString(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->reject(fn (array $group) => $group['courses'] === [])
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'qualified' => $rows->filter(fn (TrooperCourse $row) => $row->status === CourseStatus::Qualified)->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Where the ID block came from and how current it is (AD-20, AD-4): the last successful roster sync, and a
     * stale flag when a later run did not succeed, so the page says it is showing the last good copy.
     *
     * @return array{source: string, lastSuccessAt: ?string, stale: bool, lastAttempt: ?array{status: string, at: ?string}}
     */
    private function sync(): array
    {
        $success = $this->syncRuns->latestSuccessful('roster');
        $latest = $this->syncRuns->latestFinished('roster');
        $stale = $latest !== null && $latest->id !== $success?->id;

        return [
            'source' => 'Cav roster',
            'lastSuccessAt' => $success?->finished_at?->toIso8601String(),
            'stale' => $stale,
            'lastAttempt' => $stale ? ['status' => $latest->status->value, 'at' => $latest->finished_at?->toIso8601String()] : null,
        ];
    }
}
