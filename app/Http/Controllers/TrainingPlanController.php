<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\TrainingPlans\ApproveTrainingPlan;
use App\Commands\TrainingPlans\ApproveTrainingPlanHandler;
use App\Commands\TrainingPlans\InvalidTrainingPlanException;
use App\Commands\TrainingPlans\ReturnTrainingPlan;
use App\Commands\TrainingPlans\ReturnTrainingPlanHandler;
use App\Commands\TrainingPlans\SaveTrainingPlan;
use App\Commands\TrainingPlans\SaveTrainingPlanHandler;
use App\Commands\TrainingPlans\TrainingPlanRules;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanWeek;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrainingPlanRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly training plans (#380). On a platoon, its plan for one month: one training subject per Cav week, written by the
 * platoon's chain of command and reviewed by its company's. Above a platoon, the month's plans of every platoon
 * beneath, with where each stands, for the company to review. An approved plan feeds the platoon's weekly training
 * topic, which the section reports read.
 *
 * Every unit is checked against `ScopeResolver::resolve()` (AD-8, narrowed by view-as, AD-9): one outside it is a 403,
 * as on the report screen. Writes go through the audited training plan Commands, which check authority again.
 */
class TrainingPlanController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrainingPlanRepository $plans,
        private readonly TrainingPlanRules $rules,
        private readonly TrooperRepository $troopers,
    ) {}

    public function show(Request $request, int $unit, ?string $month = null): Response
    {
        $unit = $this->unitInScope($request, $unit);
        $month ??= CarbonImmutable::now('UTC')->format('Y-m');
        $weeks = $this->weeksOf($month);
        // A section has no plan of its own: its platoon writes it.
        abort_if($unit->type === UnitType::Section, 404);

        $first = CarbonImmutable::parse("{$month}-01", 'UTC');
        $shared = [
            'unit' => $this->unitProps($unit),
            'month' => [
                'value' => $month,
                'label' => $first->format('F Y'),
                'previous' => TrainingPlanRules::inRange($first->subMonthNoOverflow()->format('Y-m')) ? $first->subMonthNoOverflow()->format('Y-m') : null,
                'next' => TrainingPlanRules::inRange($first->addMonthNoOverflow()->format('Y-m')) ? $first->addMonthNoOverflow()->format('Y-m') : null,
            ],
        ];

        if ($unit->type !== UnitType::Platoon) {
            return Inertia::render('Reports/TrainingPlan', [...$shared, 'platoons' => $this->platoonRows($unit, $month)]);
        }

        $plan = $this->plans->find($unit->id, $month);
        $subjects = $plan?->weeks->mapWithKeys(fn (TrainingPlanWeek $week) => [$week->week_start->toDateString() => $week->subject]) ?? collect();
        $locked = $this->rules->lockedWeeks($unit, $weeks);
        // A view-as preview changes nothing: offer no write while one is active.
        $previewing = $this->scope->activeViewAs($request->user()) !== null;

        return Inertia::render('Reports/TrainingPlan', [
            ...$shared,
            'weeks' => array_map(fn (CarbonImmutable $week) => [
                'start' => $week->toDateString(),
                'subject' => $subjects->get($week->toDateString()),
                'locked' => $locked[$week->toDateString()] ?? null,
                'reportUrl' => $week->lessThanOrEqualTo(WeekOf::start(CarbonImmutable::now('UTC'))) ? route('reports.show', ['unit' => $unit->id, 'week' => $week->toDateString()], false) : null,
            ], $weeks),
            'plan' => $plan === null ? null : $this->planProps($plan),
            // Command authority, never a staff grant, which only reads (AD-8).
            'canEdit' => ! $previewing && $this->rules->mayWrite($request->user(), $unit),
            'canReview' => ! $previewing && $this->rules->mayReview($request->user(), $unit),
            'company' => ($company = $this->units->owningCompany($unit)) === null ? null : $this->unitProps($company),
        ]);
    }

    public function save(Request $request, SaveTrainingPlanHandler $handler, int $unit, string $month): RedirectResponse
    {
        $unit = $this->unitInScope($request, $unit);
        $this->weeksOf($month);
        $data = $request->validate([
            'subjects' => ['present', 'array', 'max:5'],
            'subjects.*' => ['nullable', 'string', 'max:255'],
            'enforced' => ['sometimes', 'boolean'],
        ]);

        $this->asFormErrors(fn () => $handler->handle($request->user(), new SaveTrainingPlan($unit->id, $month, $data['subjects'], (bool) ($data['enforced'] ?? false))));

        return $this->back($unit, $month);
    }

    public function approve(Request $request, ApproveTrainingPlanHandler $handler, int $unit, string $month): RedirectResponse
    {
        $unit = $this->unitInScope($request, $unit);
        $this->weeksOf($month);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        $this->asFormErrors(fn () => $handler->handle($request->user(), new ApproveTrainingPlan($unit->id, $month, (int) $data['revision'])));

        return $this->back($unit, $month);
    }

    public function sendBack(Request $request, ReturnTrainingPlanHandler $handler, int $unit, string $month): RedirectResponse
    {
        $unit = $this->unitInScope($request, $unit);
        $this->weeksOf($month);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        $this->asFormErrors(fn () => $handler->handle($request->user(), new ReturnTrainingPlan($unit->id, $month, (int) $data['revision'], $data['comment'])));

        return $this->back($unit, $month);
    }

    /** A Command's refusal the leader can act on is a form error; an authority refusal stays a 403. */
    private function asFormErrors(callable $write): void
    {
        try {
            $write();
        } catch (InvalidTrainingPlanException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }
    }

    private function back(Unit $unit, string $month): RedirectResponse
    {
        return redirect()->route('training-plans.show', ['unit' => $unit->id, 'month' => $month]);
    }

    private function unitInScope(Request $request, int $unitId): Unit
    {
        $unit = $this->scope->resolve($request->user())->contains($unitId) ? $this->units->find($unitId) : null;

        if ($unit === null) {
            abort(403, 'That unit is outside your scope.');
        }

        // A group off the line has no platoons and no plans.
        abort_unless($unit->isLine(), 404);

        return $unit;
    }

    /**
     * The Cav weeks of `$month`, or a 404 for a month that is not `YYYY-MM` or is outside the plan window (from the
     * week floor up to a year ahead).
     *
     * @return list<CarbonImmutable>
     */
    private function weeksOf(string $month): array
    {
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 && TrainingPlanRules::inRange($month), 404);

        return WeekOf::inMonth($month);
    }

    /**
     * Every platoon under `$unit` with where its plan for the month stands, in tree order.
     *
     * @return list<array<string, mixed>>
     */
    private function platoonRows(Unit $unit, string $month): array
    {
        $platoons = $this->units->lineSubtree($unit)->filter(fn (Unit $u) => $u->type === UnitType::Platoon)->values();
        $plans = $this->plans->forUnits($platoons->pluck('id')->all(), $month);

        return $platoons->map(fn (Unit $platoon) => [
            ...$this->unitProps($platoon),
            'status' => $plans->get($platoon->id)?->status->value ?? 'none',
            'weeksPlanned' => $plans->get($platoon->id)?->weeks->count() ?? 0,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function planProps(TrainingPlan $plan): array
    {
        $cavIds = collect([$plan->writer?->cav_id, $plan->reviewer?->cav_id])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $names = $cavIds === [] ? collect() : $this->troopers->byCavId($cavIds);

        return [
            'status' => $plan->status->value,
            'enforced' => $plan->enforced,
            'revision' => $plan->revision,
            'writtenBy' => $this->who($plan->writer, $plan->writerAdmin, $plan->writtenAsUnit, $names),
            'writtenAt' => $plan->updated_at?->toIso8601String(),
            'reviewedBy' => $plan->reviewer === null ? null : $this->who($plan->reviewer, $plan->reviewerAdmin, $plan->reviewedAsUnit, $names),
            'reviewedAt' => $plan->reviewed_at?->toIso8601String(),
            'comment' => $plan->review_comment,
        ];
    }

    /**
     * `RANK Name` from the roster (or the forum username), as the report screen names a topic's setter: "Hale.S for
     * SGT Vasquez.R" when an Admin acting as them did it, "Hale.S acting as Platoon Leader 1/A/1-7" when an Admin
     * holding the billet did (#379).
     */
    private function who(?User $user, ?User $admin, ?Unit $asUnit, Collection $names): string
    {
        $trooper = $user?->cav_id === null ? null : $names->get((int) $user->cav_id);

        return ($admin === null ? '' : $admin->username.' for ')
            .($trooper?->display_name ?? $user?->username)
            .($asUnit === null ? '' : ' acting as '.$asUnit->billet());
    }

    /**
     * @return array{id: int, name: string, callsign: ?string, type: string}
     */
    private function unitProps(Unit $unit): array
    {
        return ['id' => $unit->id, 'name' => $unit->name, 'callsign' => $unit->callsign, 'type' => $unit->type->value];
    }
}
