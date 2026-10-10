<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\LeaveCoversWeekException;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallScreen;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly rollcall for one section (Stories 3.7, 3.8): one page, one controller. It orchestrates only; what the page shows
 * comes from {@see RollcallScreen}.
 *
 * Who may open a section is decided here by the single ScopeResolver (AD-8, AD-6): a section outside the viewer's
 * effective scope is a 403 whatever link led there, and a view-as preview narrows it as it would for that leader
 * (AD-9), and a write is gated again by the section's scope inside {@see MarkAttendanceHandler} (AD-8). The week is `?week=YYYY-MM-DD`, any day in the Cav week; a week that is not a date, or lies in the future, falls
 * back to the current one, since a rollcall is never kept ahead of time.
 */
class RollcallController extends Controller
{
    /** The most marks one save may carry. A section holds a few dozen troopers; this only bounds a malformed request. */
    private const MAX_MARKS = 500;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly RollcallScreen $screen,
        private readonly MarkAttendanceHandler $handler,
    ) {}

    /**
     * The nav entry: the first section in the viewer's scope, or an empty state for someone who has none.
     */
    public function index(Request $request): RedirectResponse|Response
    {
        $sections = $this->sections($request);
        $first = $sections->first();

        if ($first === null) {
            return Inertia::render('Rollcall', ['section' => null, 'sections' => []]);
        }

        return redirect()->route('rollcall.show', $first->id);
    }

    public function show(Request $request, Unit $unit): RedirectResponse|Response
    {
        abort_unless($unit->type === UnitType::Section, 404, 'Rollcall is kept per section.');
        abort_unless($this->scope->resolve($request->user())->contains($unit->id), 403, 'That section is outside your scope.');

        $current = WeekOf::start(CarbonImmutable::now());
        $week = $this->requestedWeek($request, $current);

        if ($week === null) {
            return redirect()->route('rollcall.show', ['unit' => $unit->id]);
        }

        return Inertia::render('Rollcall', $this->screen->for($unit, $week) + [
            // A trooper's name opens their milpac once that page exists (Epic 6); until then it is plain text, as the
            // roster Sheet does for rollcall (Story 3.7), rather than a link to a 404.
            'links' => ['milpac' => Route::has('troopers.show') ? '/troopers/' : null],
            'sections' => $this->sections($request)->map(fn (Unit $section) => [
                'id' => $section->id,
                'name' => $section->name,
                'callsign' => $section->callsign,
            ])->values()->all(),
        ]);
    }

    /**
     * Save the week's marks (Story 3.8): every entry becomes a manual mark through the audited
     * {@see MarkAttendanceHandler}, one counting row per trooper and practice, so submitting a week again updates
     * the rows rather than adding any. `loa` is not a status a leader can send, and a trooper whose leave covers the
     * week is refused by the handler, so neither can be written by hand. A submission with no marks is valid: it only
     * records that the section's practice exists.
     *
     * A week still to come is refused. A past week may be corrected, since the audit log records the change.
     */
    public function store(Request $request, Unit $unit): RedirectResponse
    {
        abort_unless($unit->type === UnitType::Section, 404, 'Rollcall is kept per section.');
        abort_unless($this->scope->resolve($request->user())->contains($unit->id), 403, 'That section is outside your scope.');

        $data = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'marks' => ['present', 'array', 'max:'.self::MAX_MARKS],
            'marks.*.trooper_id' => ['required', 'integer'],
            'marks.*.status' => ['nullable', Rule::in(array_map(fn (AttendanceStatus $status) => $status->value, array_filter(AttendanceStatus::cases(), fn (AttendanceStatus $status) => $status !== AttendanceStatus::Loa)))],
            'marks.*.rollcall' => ['nullable', Rule::enum(RollcallSignature::class)],
        ]);

        $week = WeekOf::start(CarbonImmutable::parse($data['week'], 'UTC'));

        if ($week->greaterThan(WeekOf::start(CarbonImmutable::now()))) {
            throw ValidationException::withMessages(['week' => 'A rollcall cannot be saved for a week that has not begun.']);
        }

        try {
            $marks = array_map(fn (array $mark) => new AttendanceMark(
                (int) $mark['trooper_id'],
                filled($mark['status'] ?? null) ? AttendanceStatus::from($mark['status']) : null,
                filled($mark['rollcall'] ?? null) ? RollcallSignature::from($mark['rollcall']) : null,
            ), $data['marks']);

            $this->handler->handle($request->user(), new MarkAttendance($unit->id, $week, ...$marks));
        } catch (LeaveCoversWeekException $e) {
            // The leave synced after the page loaded, so say who it is by name rather than by id.
            $names = $this->troopers->placedWithin($unit)->whereIn('id', $e->trooperIds)->pluck('display_name')->all();

            throw ValidationException::withMessages(['marks' => sprintf(
                '%s %s on leave this week and cannot be marked by hand. Reload the page to see them as LOA.',
                implode(', ', $names),
                count($names) === 1 ? 'is' : 'are',
            )]);
        } catch (InvalidAttendanceMarkException $e) {
            throw ValidationException::withMessages(['marks' => $e->getMessage()]);
        }

        return redirect()->route('rollcall.show', ['unit' => $unit->id, 'week' => $week->toDateString()]);
    }

    /**
     * The requested week's start, the current week when none is asked for, or null when the request names something
     * that cannot be shown (not a date, or a week still to come) so the caller drops it.
     */
    private function requestedWeek(Request $request, CarbonImmutable $current): ?CarbonImmutable
    {
        $raw = $request->query('week');

        if ($raw === null) {
            return $current;
        }

        try {
            $week = WeekOf::start(CarbonImmutable::parse((string) $raw, 'UTC'));
        } catch (InvalidFormatException) {
            return null;
        }

        return $week->greaterThan($current) ? null : $week;
    }

    /**
     * Every section in the viewer's effective scope, in tree order: what the section picker lists.
     *
     * @return Collection<int, Unit>
     */
    private function sections(Request $request): Collection
    {
        $scope = $this->scope->resolve($request->user());

        return $this->units->tree()
            ->filter(fn (Unit $unit) => $unit->type === UnitType::Section && $scope->contains($unit->id))
            ->values();
    }
}
