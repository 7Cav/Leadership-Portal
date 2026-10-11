<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Models\PlacementSource;
use App\Models\SyncRunStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A section's roster (Story 2.8): the troopers placed in a section, with where the data comes from
 * and when it was last refreshed (AD-20). Reads the repositories directly, there is no
 * `ReadServices/` layer (CONTRIBUTING §2). Visibility comes only from {@see ScopeResolver} (AD-8):
 * a section outside the viewer's effective scope is refused server-side, whatever the page offered,
 * and a view-as preview narrows it as it would for that leader (AD-9).
 */
class SectionRosterController extends Controller
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * The sidebar's Roster entry: opens the first section in the viewer's scope, in chain-of-command
     * order. A viewer with no section in scope gets the page's empty state instead.
     */
    public function index(Request $request, ScopeResolver $scope): RedirectResponse|Response
    {
        $sections = $this->sectionsInScope($this->scopeSet($request->user(), $scope));

        if ($sections->isEmpty()) {
            return Inertia::render('Section/Roster', ['section' => null, 'sections' => [], 'troopers' => [], 'sync' => null]);
        }

        return redirect()->route('roster.show', $sections->first()['id']);
    }

    /**
     * The scope check runs before the section-type check, so a unit outside the viewer's scope is
     * refused with 403 whatever it is: answering 404 for a non-section first would tell an out-of-scope
     * viewer that unit's type.
     */
    public function show(Request $request, Unit $unit, ScopeResolver $scope): Response
    {
        // Resolved once: it re-queries grants, command mappings and subtrees, and re-evaluates view-as.
        $inScope = $this->scopeSet($request->user(), $scope);
        abort_unless($inScope->has($unit->id), 403, 'That unit is outside your scope.');

        abort_unless($unit->type === UnitType::Section, 404);

        return Inertia::render('Section/Roster', [
            'section' => ['id' => $unit->id, 'name' => $unit->name, 'callsign' => $unit->callsign],
            'sections' => $this->sectionsInScope($inScope)->all(),
            'troopers' => $this->troopers->inSection($unit->id)->map(fn ($trooper) => [
                'id' => $trooper->id,
                'username' => $trooper->username,
                'cav_id' => $trooper->cav_id,
                'rank' => $trooper->rank,
                'mos' => $trooper->mos,
                'status' => $trooper->status->value,
                // Shown so a leader can see why someone is on the roster when Cav's billet does not say so.
                'placed_by_hand' => $trooper->placement_source === PlacementSource::Manual,
            ])->all(),
            'sync' => $this->syncState(),
        ]);
    }

    /**
     * The viewer's effective scope (AD-8) as a set keyed by unit id, so membership is a lookup.
     *
     * @return Collection<int, int>
     */
    private function scopeSet(User $user, ScopeResolver $scope): Collection
    {
        return $scope->resolve($user)->flip();
    }

    /**
     * The sections the viewer may open, in tree order, for the page's switcher.
     *
     * @param  Collection<int, int>  $inScope  unit ids as keys, from {@see scopeSet()}
     * @return Collection<int, array{id: int, name: string, callsign: ?string}>
     */
    private function sectionsInScope(Collection $inScope): Collection
    {
        return $this->units->tree()
            ->filter(fn (Unit $unit) => $unit->type === UnitType::Section && $inScope->has($unit->id))
            ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name, 'callsign' => $unit->callsign])
            ->values();
    }

    /**
     * Where the roster came from and how current it is (AD-20, AD-4). `lastSyncedAt` is the last
     * *successful* run, never a failed attempt's time. `stale` is set when the latest finished run
     * did not succeed, so the page can say it is showing the last good cache.
     *
     * @return array{source: string, lastSyncedAt: ?string, stale: bool, latestStatus: ?string, latestFinishedAt: ?string}
     */
    private function syncState(): array
    {
        $latest = $this->syncRuns->latestFinished('roster');
        $lastGood = $this->syncRuns->latestSuccessful('roster');

        return [
            'source' => 'Cav roster',
            'lastSyncedAt' => $lastGood?->finished_at?->toIso8601String(),
            'stale' => $latest !== null && $latest->status !== SyncRunStatus::Success,
            'latestStatus' => $latest?->status->value,
            'latestFinishedAt' => $latest?->finished_at?->toIso8601String(),
        ];
    }
}
