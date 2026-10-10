<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallScreen;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly rollcall for one section (Story 3.7): one page, one controller. It orchestrates only; what the page shows
 * comes from {@see RollcallScreen}.
 *
 * Who may open a section is decided here by the single ScopeResolver (AD-8, AD-6): a section outside the viewer's
 * effective scope is a 403 whatever link led there, and a view-as preview narrows it as it would for that leader
 * (AD-9). The week is `?week=YYYY-MM-DD`, any day in the Cav week; a week that is not a date, or lies in the future, falls
 * back to the current one, since a rollcall is never kept ahead of time.
 */
class RollcallController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly RollcallScreen $screen,
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
            'sections' => $this->sections($request)->map(fn (Unit $section) => [
                'id' => $section->id,
                'name' => $section->name,
                'callsign' => $section->callsign,
            ])->values()->all(),
        ]);
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
