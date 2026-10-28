<?php

namespace App\Milpac;

use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CourseRepository;
use App\Repositories\TrooperCourseRepository;
use App\Repositories\TrooperRepository;
use Illuminate\Support\Collection;

/**
 * What the training-readiness matrix shows for one unit (Story 6.9): the active troopers placed anywhere in its
 * subtree as rows, the whole course catalog as columns, and each cell the trooper's status on that course.
 * Read-only, and it never decides who may look: the caller gates the unit with the ScopeResolver (AD-8) first.
 *
 * Each row carries the trooper's `role`, the billet tag the roster shows (`CO`, `PL`, `SL`, `Acting PL`... or null),
 * decided in one place with the roster ({@see BilletTags}), so the page can list a unit leader-first without guessing
 * from billet text. The page owns the ordering (it reuses the roster's `compareBillet`), so rows arrive in id order.
 *
 * Every course in the catalog is a column even when no trooper holds or needs it, so a bottleneck cannot vanish
 * from view. A trooper with no row for a course reads `none`, the same as a stored `none`.
 *
 * Coverage is a plain ratio under each column, qualified over every trooper shown, as the story defines it. It is
 * not a canonical KPI and deliberately does not go through the KPI service (AD-21). It is null when there are no
 * troopers, never 0 of 0.
 */
final class ReadinessMatrix
{
    public function __construct(
        private readonly TrooperRepository $troopers,
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly CourseRepository $courses,
        private readonly BilletTags $tags,
    ) {}

    /**
     * @return array{
     *     crumb: string,
     *     unit: array{id: int, name: string, callsign: ?string, type: string},
     *     courses: list<array{id: int, code: string, name: string}>,
     *     troopers: list<array{id: int, displayName: string, rankShort: ?string, billet: ?string, role: ?string, statuses: array<int, string>}>,
     *     coverage: list<array{courseId: int, qualified: int, total: int, percent: ?int}>
     * }
     */
    public function for(Unit $unit): array
    {
        $troopers = $this->troopers->activePlacedWithin($unit);
        $tags = $this->tags->of($troopers);

        $statuses = $this->trooperCourses->forTroopers($troopers->pluck('id')->all())
            ->groupBy('trooper_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->course_id => $row->status]));

        $courses = $this->courses->all();

        $rows = $troopers->map(fn (Trooper $trooper) => [
            'id' => $trooper->id,
            'displayName' => $trooper->display_name,
            'rankShort' => $trooper->rank_short,
            'billet' => $trooper->billet,
            'role' => $tags->get($trooper->id),
            'statuses' => $courses->mapWithKeys(fn ($course) => [
                $course->id => ($statuses->get($trooper->id)?->get($course->id) ?? CourseStatus::None)->value,
            ])->all(),
        ])->all();

        return [
            // The page's last breadcrumb crumb (the shell reads `crumb` for a page nested under a nav item).
            'crumb' => 'Training readiness',
            'unit' => ['id' => $unit->id, 'name' => $unit->name, 'callsign' => $unit->callsign, 'type' => $unit->type->value],
            'courses' => $courses->map(fn ($course) => ['id' => $course->id, 'code' => $course->code, 'name' => $course->name])->values()->all(),
            'troopers' => $rows,
            'coverage' => $this->coverage($courses, $rows),
        ];
    }

    /**
     * Qualified over every trooper shown, per course; `percent` is null when no trooper is shown (never 0 of 0).
     *
     * @param  Collection<int, Course>  $courses
     * @param  list<array{statuses: array<int, string>}>  $rows
     * @return list<array{courseId: int, qualified: int, total: int, percent: ?int}>
     */
    private function coverage(Collection $courses, array $rows): array
    {
        $total = count($rows);

        return $courses->map(function (Course $course) use ($rows, $total) {
            $qualified = count(array_filter($rows, fn (array $row) => $row['statuses'][$course->id] === CourseStatus::Qualified->value));

            return [
                'courseId' => $course->id,
                'qualified' => $qualified,
                'total' => $total,
                'percent' => $total === 0 ? null : (int) round($qualified * 100 / $total),
            ];
        })->values()->all();
    }
}
