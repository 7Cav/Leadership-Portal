<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Demo\GenerateDemoHistory;
use App\Commands\Demo\GenerateDemoHistoryHandler;
use App\Commands\Demo\PurgeDemoHistory;
use App\Commands\Demo\PurgeDemoHistoryHandler;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\DemoHistoryRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Demo data (#115): generate and purge demo attendance history for a unit's real
 * troopers. The whole screen exists only while `portal.demo_tools` is on — otherwise every route
 * answers 404, as if it were not there — and is Admin-only: viewing follows a view-as preview
 * (`actsAsAdmin`, like the audit log), writes check the real flag in the handlers, and 1.13's
 * middleware refuses any write while a preview is active.
 */
class DemoDataController extends Controller
{
    /** The echelons the picker offers: each one's whole subtree is generated. */
    private const PICKABLE = [UnitType::Battalion, UnitType::Company, UnitType::Platoon, UnitType::Section];

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly DemoHistoryRepository $demo,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();

        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may manage demo data.');
        }

        $inScope = $this->scope->resolve($request->user())->flip();
        $tree = $this->units->tree();
        $placed = $this->demo->placedCountsBySection();
        $sectionPaths = $tree->filter(fn (Unit $unit) => $unit->type === UnitType::Section)
            ->mapWithKeys(fn (Unit $unit) => [$unit->id => $unit->path]);

        $units = $tree
            ->filter(fn (Unit $unit) => $inScope->has($unit->id) && in_array($unit->type, self::PICKABLE, true))
            // Creation order within each echelon (A before B, 1st before 2nd): `path` segments are
            // ids, which sort wrongly as text (`1/24/` before `1/3/`).
            ->sortBy(fn (Unit $unit) => array_map('intval', explode('/', trim($unit->path, '/'))))
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'type' => $unit->type->value,
                'name' => $unit->name,
                'callsign' => $unit->callsign,
                'troopers' => $sectionPaths
                    ->filter(fn (string $path) => str_starts_with($path, $unit->path))
                    ->keys()
                    ->sum(fn (int $sectionId) => $placed->get($sectionId, 0)),
            ])
            ->values();

        return Inertia::render('Settings/DemoData', [
            'units' => $units,
            'existing' => $this->demo->count()->toArray(),
            'weeks' => ['default' => GenerateDemoHistory::DEFAULT_WEEKS, 'max' => GenerateDemoHistory::MAX_WEEKS],
            // The completed Cav weeks a generation can cover, oldest first (AD-11), so the confirm
            // dialog can name the exact span without re-deriving week boundaries in the browser.
            'recentWeeks' => array_map(
                fn (int $back) => WeekOf::start(CarbonImmutable::now('UTC'))->subWeeks($back)->toDateString(),
                range(GenerateDemoHistory::MAX_WEEKS, 1),
            ),
            // The outcome of the generate or purge just run, flashed for one render.
            'result' => $request->session()->get('demo_result'),
        ]);
    }

    public function store(Request $request, GenerateDemoHistoryHandler $handler): RedirectResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'weeks' => ['required', 'integer', 'min:1', 'max:'.GenerateDemoHistory::MAX_WEEKS],
        ]);

        $result = $handler->handle($request->user(), new GenerateDemoHistory((int) $data['unit_id'], (int) $data['weeks']));

        return to_route('settings.demo-data')->with('demo_result', [
            'action' => 'generated',
            'empty' => $result->hasNoTroopers() || $result->generatedWeeks === [],
            ...$result->toArray(),
        ]);
    }

    public function destroy(Request $request, PurgeDemoHistoryHandler $handler): RedirectResponse
    {
        $this->ensureEnabled();

        $data = $request->validate(['unit_id' => ['required', 'integer']]);

        $result = $handler->handle($request->user(), new PurgeDemoHistory((int) $data['unit_id']));

        return to_route('settings.demo-data')->with('demo_result', [
            'action' => 'purged',
            'empty' => $result->deleted->isEmpty(),
            ...$result->toArray(),
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('portal.demo_tools'), 404);
    }
}
