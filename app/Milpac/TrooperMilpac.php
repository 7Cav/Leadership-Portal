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
 * What the trooper detail page (the milpac) shows for one trooper (Stories 6.3 to 6.5): the ID block, the
 * courses and qualifications panel and the awards history, read through the repositories and shaped as page props. Read-only, and it
 * never decides who may look: the caller gates that with the ScopeResolver (AD-8) before asking.
 *
 * The ID block is the cached Cav roster, so the page can show where it came from and how fresh it is (AD-20).
 * Durations are counted on the server's UTC day (AD-11) so every viewer reads the same figure.
 */
final class TrooperMilpac
{
    /** The order the course groups are shown in. `none` is left out: it means the trooper has nothing on the course. */
    private const GROUPS = [CourseStatus::Qualified, CourseStatus::InProgress, CourseStatus::Required];

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * @return array{crumb: string, trooper: array<string, mixed>, awards: array{available: bool, items: list<array{name: string, awardedAt: ?string, citation: ?string}>}, courses: array{groups: list<array{status: string, label: string, courses: list<array{code: string, name: string, awardedAt: ?string}>}>, qualified: int, total: int}, sync: array<string, mixed>}
     */
    public function for(Trooper $trooper): array
    {
        return [
            // The page's last breadcrumb crumb (the shell reads `crumb` for a page nested under a nav item).
            'crumb' => $trooper->display_name,
            'trooper' => $this->idBlock($trooper),
            'courses' => $this->courses($trooper),
            'awards' => $this->awards(),
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
        $departed = $trooper->status === TrooperStatus::Inactive;

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
            'departed' => $departed,
            'tis' => $trooper->tis?->toDateString(),
            // The roster records no departure date, so a departed trooper's tenure to today would be wrong
            // and ever-growing: leave it unknown rather than show that figure.
            'tisMonths' => $departed ? null : $this->monthsSince($trooper->tis?->toDateString(), $today),
            'tig' => $trooper->tig?->toDateString(),
            'tigMonths' => $departed ? null : $this->monthsSince($trooper->tig?->toDateString(), $today),
            'section' => $section === null ? null : ['id' => $section->id, 'name' => $section->name, 'callsign' => $section->callsign],
            'milpacUrl' => Trooper::MILPAC_PROFILE_URL.$trooper->cav_id,
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
     * with nothing in it is not returned. `qualified` and `total` are counted from the groups returned, so the
     * header count can never disagree with the list under it.
     *
     * @return array{groups: list<array{status: string, label: string, courses: list<array{code: string, name: string, awardedAt: ?string}>}>, qualified: int, total: int}
     */
    private function courses(Trooper $trooper): array
    {
        /** @var Collection<int, TrooperCourse> $rows */
        $rows = $this->trooperCourses->forTrooper($trooper->id);

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
            ->values();

        return [
            'groups' => $groups->all(),
            'qualified' => count($groups->firstWhere('status', CourseStatus::Qualified->value)['courses'] ?? []),
            'total' => $groups->sum(fn (array $group) => count($group['courses'])),
        ];
    }

    /**
     * The earned-awards history (Story 6.5): read-only, from the Cav-authoritative roster (AD-5), never a
     * recommendation workflow (FR17). The roster cache holds no awards today (`/lite` carries none and AD-3 rules
     * out a new outbound read for them), so `available` is false and the page says the awards are not synced
     * rather than claiming the trooper has none: unknown is not the same as none. Once a source exists this is
     * where it is read, newest first, as `{name, awardedAt, citation}`, with `available` true.
     *
     * @return array{available: bool, items: list<array{name: string, awardedAt: ?string, citation: ?string}>}
     */
    private function awards(): array
    {
        return ['available' => false, 'items' => []];
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
