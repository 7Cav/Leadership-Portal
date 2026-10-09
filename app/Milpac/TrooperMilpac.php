<?php

namespace App\Milpac;

use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperCourseRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * What the trooper detail page (the milpac) shows for one trooper (Stories 6.3 to 6.7): the ID block, the
 * courses and qualifications panel, the earned-awards history, the service-record timeline and the leadership quick actions, read through the
 * repositories and shaped as page props. Read-only, and it never decides who may look: the caller gates that with
 * the ScopeResolver (AD-8) before asking.
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

    /**
     * The timeline's event types, in the order that settles two events on the same date (Story 6.6), with the
     * legend's wording. A type with nothing to show is left out of the legend as well as the list.
     */
    private const TIMELINE_TYPES = ['promotion' => 'Promotion', 'award' => 'Award', 'course' => 'Course'];

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * @param  Collection<int, int>  $scope  the unit ids the viewer may read, from the ScopeResolver (AD-8)
     * @return array{trooper: array<string, mixed>, courses: array<string, mixed>, awards: array<string, mixed>, timeline: array<string, mixed>, actions: list<array{key: string, label: string, href: string}>, sync: array<string, mixed>}
     */
    public function for(Trooper $trooper, Collection $scope): array
    {
        $rows = $this->trooperCourses->forTrooper($trooper->id);
        $awards = $this->awards($trooper);

        return [
            'trooper' => $this->idBlock($trooper),
            'courses' => $this->courses($rows),
            'awards' => $awards,
            'timeline' => $this->timeline($trooper, $rows, $awards['items']),
            'actions' => $this->actions($trooper, $scope),
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
     * @param  Collection<int, TrooperCourse>  $all
     * @return array{groups: list<array<string, mixed>>, qualified: int, total: int}
     */
    private function courses(Collection $all): array
    {
        /** @var Collection<int, TrooperCourse> $rows */
        $rows = $all->reject(fn (TrooperCourse $row) => $row->status === CourseStatus::None);

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
     * The earned-awards history (Story 6.5): read-only, from the Cav-authoritative roster (AD-5), never a
     * recommendation workflow (FR17). The roster sync stores no awards today (`/lite` carries none and AD-3 rules
     * out a new outbound read for them), so every trooper gets the empty list and the page shows its empty state.
     * When the roster cache starts holding awards, they are returned here as `{name, awardedAt, citation}`, newest
     * first; the page already draws them.
     *
     * @return array{items: list<array{name: string, awardedAt: ?string, citation: ?string}>}
     */
    private function awards(Trooper $trooper): array
    {
        return ['items' => []];
    }

    /**
     * The service-record timeline (Story 6.6): what the milpac already holds, newest first. Three kinds of
     * event exist today: the last promotion (the roster's date in grade and current rank, so earlier ones are
     * not known), earned awards and qualified courses that carry a date. A course with no award date cannot be
     * placed in time and is left to the courses panel; operations and discipline have no source yet, so they
     * have no type here until one exists. A trooper whose grade date is their join date has not been
     * promoted, and a grade date in the future is a bad sync value, so neither shows a promotion.
     *
     * Two events on one day keep a fixed order: promotion, then award, then course, then the row's own id, so
     * a reload never reshuffles them.
     *
     * @param  Collection<int, TrooperCourse>  $courseRows
     * @param  list<array{name: string, awardedAt: ?string, citation: ?string}>  $awards
     * @return array{entries: list<array<string, mixed>>, legend: list<array{type: string, label: string}>}
     */
    private function timeline(Trooper $trooper, Collection $courseRows, array $awards): array
    {
        $events = [];
        $tig = $trooper->tig?->toDateString();

        // A grade date after today is a bad sync value; the ID block already treats it as unknown, so no promotion is
        // drawn from it either.
        if ($tig !== null && $tig <= CarbonImmutable::now('UTC')->toDateString() && $trooper->rank !== null && $tig !== $trooper->tis?->toDateString()) {
            $events[] = ['type' => 'promotion', 'date' => $tig, 'title' => "Promoted to {$trooper->rank}", 'detail' => null, 'order' => 0];
        }

        foreach ($awards as $index => $award) {
            if ($award['awardedAt'] !== null) {
                $events[] = ['type' => 'award', 'date' => $award['awardedAt'], 'title' => $award['name'], 'detail' => $award['citation'], 'order' => $index];
            }
        }

        foreach ($courseRows as $row) {
            if ($row->status === CourseStatus::Qualified && $row->awarded_at !== null) {
                $events[] = ['type' => 'course', 'date' => $row->awarded_at->toDateString(), 'title' => $row->course->name, 'detail' => $row->course->code, 'order' => $row->id];
            }
        }

        $priority = array_flip(array_keys(self::TIMELINE_TYPES));

        usort($events, fn (array $a, array $b) => $b['date'] <=> $a['date']
            ?: $priority[$a['type']] <=> $priority[$b['type']]
            ?: $a['order'] <=> $b['order']);

        $present = array_unique(array_column($events, 'type'));

        return [
            'entries' => array_map(fn (array $event) => array_diff_key($event, ['order' => 0]), $events),
            'legend' => collect(self::TIMELINE_TYPES)
                ->filter(fn (string $label, string $type) => in_array($type, $present, true))
                ->map(fn (string $label, string $type) => ['type' => $type, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /**
     * The leadership quick actions (Story 6.7): links from the milpac to the rest of the portal, shown only to a
     * viewer whose scope holds the unit each one acts on. Hiding is cosmetic (AD-6): every target route authorizes
     * the request itself, so a link typed by hand is refused the same way.
     *
     * An action whose route is not registered yet is left out rather than linked to a 404, the same rule the roster
     * Sheet follows for rollcall (3.7); the readiness matrix arrives with 6.9. Nothing here changes a trooper:
     * messaging, leave on someone's behalf, medal recommendations and promotions are not read-only and are not
     * offered. A trooper the sync has not placed in a section has no unit to act on, so has no actions.
     *
     * @param  Collection<int, int>  $scope
     * @return list<array{key: string, label: string, href: string}>
     */
    private function actions(Trooper $trooper, Collection $scope): array
    {
        $section = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);

        if ($section === null) {
            return [];
        }

        $platoon = $section->parent_id === null ? null : $this->units->find($section->parent_id);
        $platoon = $platoon?->type === UnitType::Platoon ? $platoon : null;
        $actions = [];

        if ($scope->contains($section->id)) {
            $actions[] = ['key' => 'section-roster', 'label' => 'Open section roster', 'href' => route('roster.index', $section->id, absolute: false)];

            if (Route::has('rollcall.show')) {
                $actions[] = ['key' => 'rollcall', 'label' => 'Open in rollcall', 'href' => '/rollcall/'.$section->id];
            }
        }

        if ($platoon !== null && $scope->contains($platoon->id) && Route::has('readiness.matrix')) {
            $actions[] = ['key' => 'readiness-matrix', 'label' => 'View platoon readiness matrix', 'href' => route('readiness.matrix', $platoon->id, absolute: false)];
        }

        return $actions;
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
