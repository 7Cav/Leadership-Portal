<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Units\ActingLeadForbiddenException;
use App\Commands\Units\AssignActingLead;
use App\Commands\Units\AssignActingLeadHandler;
use App\Commands\Units\CreateUnit;
use App\Commands\Units\DeleteUnit;
use App\Commands\Units\ImportOrgStructure;
use App\Commands\Units\InvalidActingLeadException;
use App\Commands\Units\InvalidUnitGameException;
use App\Commands\Units\InvalidUnitOrdinalException;
use App\Commands\Units\InvalidUnitOversightException;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Commands\Units\OrgStructureImportFailedException;
use App\Commands\Units\RemoveActingLead;
use App\Commands\Units\RemoveActingLeadHandler;
use App\Commands\Units\SetUnitGame;
use App\Commands\Units\SetUnitLabel;
use App\Commands\Units\SetUnitOversight;
use App\Commands\Units\SetUnitOversightHandler;
use App\Commands\Units\StaffBillet;
use App\Commands\Units\UnitHasDependentsException;
use App\Commands\Units\UnitOversightForbiddenException;
use App\Commands\Units\UpdateUnit;
use App\Http\Controllers\Controller;
use App\Kpi\Headcount;
use App\Models\Game;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitOversight;
use App\Models\UnitType;
use App\Repositories\GameRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitGuidonRepository;
use App\Repositories\UnitLeadRepository;
use App\Repositories\UnitOversightRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Org structure settings (Story 1.8, AD-18). Reads `UnitRepository` directly per the slim,
 * Laravel-idiomatic layer model — no `ReadServices/` layer exists in this codebase (deviates
 * from the issue's Implementation Notes, which predate that consolidation; see the story
 * artifact). Writes stage through `CreateUnit` (AD-15).
 */
class OrgStructureController extends Controller
{
    /** Most unplaced troopers sent per visit; a fresh install can put the whole roster here. */
    private const UNPLACED_LIMIT = 100;

    /** What an Admin reads when a unit's name or callsign is refused, in the words of the form. */
    private const UNIT_MESSAGES = [
        'name.required' => 'Give the unit a name.',
        'name.max' => 'Keep the name to :max characters.',
        'callsign.max' => 'Keep the callsign to :max characters.',
        'parent_id.exists' => 'That parent unit no longer exists. Reload the page and choose another.',
    ];

    /** The audit reason on acting-lead changes, worded as the other audited Admin forms are. */
    private const REASON_MESSAGES = [
        'reason.required' => 'Give a reason for the record.',
        'reason.max' => 'Keep the reason to :max characters.',
    ];

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly UnitLeadRepository $leads,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly ScopeResolver $scope,
    ) {}

    /**
     * Everyone authenticated may view the tree (AC #3); only the Admin flag gates authoring
     * (checked client-side for the hidden control, and again below on write — AC #5).
     */
    public function index(Request $request, ScopeResolver $scope, UnitGuidonRepository $guidons, Headcount $headcount, UnitOversightRepository $oversights, GameRepository $gameRepository): Response
    {
        $tree = $this->units->tree();
        // The game each unit plays, its own or the nearest ancestor's, worked out once over the tree.
        $games = $gameRepository->all()->keyBy('id');
        $gameOf = collect(UnitGame::resolve($tree))
            ->filter(fn (UnitGame $played) => $games->has($played->gameId))
            ->map(fn (UnitGame $played) => [
                'id' => $played->gameId,
                'name' => $games->get($played->gameId)->name,
                'abbreviation' => $games->get($played->gameId)->abbreviation,
                'retired' => $games->get($played->gameId)->isRetired(),
                'from' => $played->from === null ? null : ($played->from->callsign ?? $played->from->name),
            ]);
        // Troopers placed in each unit, rolled up every branch: a unit's count is the serving troopers placed anywhere
        // under its path, HQ staff on their HQ node and the groups included (2026-10-24, AD-18). New Recruits count on
        // their own group only, never in an ancestor's: they are not yet part of any unit. The prefix test relies on
        // the AD-18 convention that every `path` ends in "/", so "1/2/" can never match "1/20/".
        $byUnit = $this->troopers->countsBySection();
        $placedIn = $tree->whereIn('id', $byUnit->keys()->all())->keyBy('id');
        $leadable = $this->leads->leadableUnitIds()->flip();
        $leads = $this->leads->leadsOf($tree->modelKeys());
        $acting = $this->actingLeads->leadsOf($tree->modelKeys());
        $guidonOf = $guidons->all();
        // A line unit with nobody serving in it or under it (#442's one definition, `Headcount`). The page hides it until
        // asked, so an Admin can still reach one to rename, move or delete it.
        $withTroopers = $headcount->withoutEmptyLineUnits($tree)->pluck('id')->flip();
        $oversightOf = $oversights->forUnits($tree->modelKeys());
        // Who may change a unit's game, by `ScopeResolver::maySetUnitGame()`'s rule worked out once over the tree: an
        // Admin or Regimental HQ staff anywhere, an overseer (or DEVCOM's 1IC and 2IC) on their units and those under
        // them. None while previewing with view as, which is read-only.
        $byId = $tree->keyBy('id');
        $ancestorsOf = function (Unit $unit) use ($byId): array {
            $chain = [];
            for ($at = $byId->get($unit->parent_id), $depth = 0; $at !== null && $depth < 10; $depth++, $at = $byId->get($at->parent_id)) {
                $chain[] = $at;
            }

            return $chain;
        };
        $viewer = $request->user();
        $previewing = $scope->activeViewAs($viewer) !== null;
        $gamesEverywhere = ! $previewing && ($scope->isAdmin($viewer) || $scope->isRegimentalHqStaff($viewer));
        $gameRoots = $previewing || $gamesEverywhere ? collect() : $scope->gameUnitsOf($viewer)->pluck('id')->flip();
        $mayGame = fn (Unit $unit) => $gamesEverywhere
            || collect([$unit, ...$ancestorsOf($unit)])->contains(fn (Unit $at) => $gameRoots->has($at->id));

        $addEverywhere = ! $previewing && ($scope->isAdmin($viewer) || $scope->isRegimentalHqStaff($viewer));
        $addRoots = $previewing || $addEverywhere ? collect() : $scope->unitAddRoots($viewer);
        $mayAddUnder = function (Unit $unit) use ($addEverywhere, $addRoots, $ancestorsOf): bool {
            $below = collect(UnitType::cases())->first(fn (UnitType $type) => $type->isLine() && $unit->isLine() && $type->rank() === $unit->type->rank() + 1);
            if ($below === null) {
                return false;
            }

            return $addEverywhere || collect([$unit, ...$ancestorsOf($unit)])
                ->contains(fn (Unit $at) => $addRoots->has($at->id) && $addRoots->get($at->id) <= $below->rank() - 2);
        };

        $realAdmin = $scope->isAdmin($viewer);
        $undoUntil = function (Unit $unit) use ($previewing, $realAdmin, $viewer, $byId, $byUnit, $tree, $mayAddUnder) {
            $until = $previewing || $realAdmin ? null : $unit->undoAddUntil($viewer);
            $parent = $byId->get($unit->parent_id);

            return $until !== null && $parent !== null && $mayAddUnder($parent)
                && $byUnit->get($unit->id, 0) === 0 && ! $tree->contains('parent_id', $unit->id) ? $until : null;
        };

        $units = $tree->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'parent_id' => $unit->parent_id,
            'type' => $unit->type->value,
            'kind' => ($unit->kind ?? UnitKind::Line)->value,
            'name' => $unit->name,
            'callsign' => $unit->callsign,
            'label' => $unit->label,
            // The game an Admin set on this unit, and the one it plays: its own, else the nearest ancestor's, with the
            // unit it comes from. Null when no unit on its chain has one.
            'game_id' => $unit->game_id,
            'game' => $gameOf->get($unit->id),
            // Whether this unit may pick its own game (no unit above has one), the unit above whose game locks it
            // otherwise, and whether the viewer may change it. Under a locked unit the only change is clearing a game
            // set there before, back to the inherited one.
            'sets_game' => ($lock = Unit::gameLockOf($ancestorsOf($unit))) === null,
            'game_locked_by' => $lock === null ? null : ($lock->callsign ?? $lock->name),
            'can_set_game' => $mayGame($unit),
            // Whether the viewer may add a unit one echelon below this one (Add unit), by the same rule `store` applies.
            'can_add_child' => $mayAddUnder($unit),
            // Until when the viewer may undo adding this unit (Undo add), by the rule `destroy` applies: their own add,
            // under the hour, still empty, where they may still add it. Null for an Admin, who deletes instead, and
            // while previewing. Whether nothing else happened in it is checked on the undo itself.
            'undo_add_until' => $undoUntil($unit)?->toIso8601String(),
            // The unit's saved guidon (#446), drawn next to its name; null when it has none.
            'guidon' => $guidonOf->get($unit->id)?->design(),
            'path' => $unit->path,
            'own_count' => $byUnit->get($unit->id, 0),
            // The headcount's own rule, so this page and the roster agree: New Recruits, ELOA and the Reservists count
            // only on their own group.
            'trooper_count' => $byUnit
                ->filter(fn (int $count, int $id) => Headcount::counts($placedIn->get($id), $unit))
                ->sum(),
            // Who leads the unit now (`UnitLead`): a command billet's serving holder, a portfolio officer, or an acting
            // lead an Admin assigned. Vacant when the unit should have a lead and nobody serving fills it; no history
            // of a previous holder is kept.
            'lead' => ($lead = $leads->get($unit->id)) === null ? null : [
                'displayName' => $lead->trooper->display_name,
                'billet' => $lead->trooper->billet,
                'source' => $lead->source,
            ],
            'lead_vacant' => $leadable->has($unit->id) && ! $leads->has($unit->id),
            'acting_lead' => ($actor = $acting->get($unit->id)) === null ? null : ['id' => $actor->id, 'displayName' => $actor->display_name],
            // Whether an Admin may make someone this unit's acting lead now, by the Command's own rule: a line unit below
            // the regiment that no serving billet holder or portfolio officer leads.
            'acting_lead_allowed' => AssignActingLeadHandler::takesActingLead($unit) && AssignActingLeadHandler::ledByNoBillet($leads->get($unit->id)),
            'empty' => ! $withTroopers->has($unit->id),
            // Who oversees the unit: a battalion or the Reservists. A battalion with no
            // serving overseer reads "Oversight: vacant"; an overseer who left every roster is named, marked not serving.
            'overseeable' => UnitOversight::canOversee($unit),
            'oversight' => ($row = $oversightOf->get($unit->id)) === null ? null : [
                'trooperId' => $row->trooper->id,
                'displayName' => $row->trooper->display_name,
                'serving' => $row->trooper->status?->isServing() ?? false,
            ],
            'oversight_vacant' => $unit->type === UnitType::Battalion
                && ! ($oversightOf->get($unit->id)?->trooper->status?->isServing() ?? false),
        ])->values();

        // Follows a view-as preview (Story 1.13): authoring controls hide as they would for the
        // previewed leader. The write gates below keep reading the real flag.
        $isAdmin = $scope->actsAsAdmin($request->user());
        $visible = $scope->resolve($request->user())->flip();
        $groupIds = $tree->filter(fn (Unit $unit) => ! $unit->isLine() && $visible->has($unit->id))->modelKeys();

        return Inertia::render('Settings/OrgStructure', [
            'units' => $units,
            'isAdmin' => $isAdmin,
            // The Games list, for the game picker. Retired games are sent flagged: the picker offers none of them, but
            // names the one a unit still has.
            'games' => $games->map(fn (Game $game) => ['id' => $game->id, 'name' => $game->name, 'retired' => $game->isRetired()])->values(),
            // Troopers the sync could not place (2.6). Admin-tier only: a cached trooper's reported
            // billet is not part of any leader's scope yet, so everyone else gets null and the panel
            // is absent. Sent as props only to an Admin, never merely hidden by the page (AD-8, AD-6).
            'unplacedTroopers' => $isAdmin ? $this->troopers->unplaced(self::UNPLACED_LIMIT)->all() : null,
            // The true total, so the heading stays honest when the list above is capped.
            'unplacedTotal' => $isAdmin ? $this->troopers->unplacedCount() : null,
            // Who is in each group the viewer may see (2026-10-24), keyed by unit id: a staff department, ELOA with
            // each trooper's "was", Reservists, New Recruits. Scoped like every roster read (AD-8): a group outside
            // the viewer's scope is absent, not empty.
            'groupMembers' => $this->troopers->servingPlacedIn($groupIds)
                ->groupBy('primary_section_id')
                ->map(fn ($members) => $members->map(fn (Trooper $trooper) => [
                    'id' => $trooper->id,
                    'displayName' => $trooper->display_name,
                    'billet' => $trooper->billet,
                    'was' => $trooper->lastLineUnit === null ? null : ($trooper->lastLineUnit->callsign ?? $trooper->lastLineUnit->name),
                ])->values()->all())
                ->all(),
            // The outcome of an import just run (story 2.9), flashed by `import()` for one render.
            'importResult' => $request->session()->get('org_import'),
            // Rows shown in a second place without moving the unit: ACD and DEVCOM under
            // RDC in the staff card, DEVCOM's staff under DEVCOM in the chain of command.
            'references' => self::references($tree),
        ]);
    }

    /**
     * The reference rows the org page draws, as `{parent_id, unit_id}`: a unit shown again under a node that is not
     * its parent ({@see StaffBillet::PORTFOLIO_LINE_UNITS}, {@see StaffBillet::LINE_STAFF_GROUPS}). Matched by callsign
     * and side of the tree, so a renamed or missing unit simply has no reference.
     *
     * @param  Collection<int, Unit>  $tree
     * @return list<array{parent_id: int, unit_id: int}>
     */
    private static function references(Collection $tree): array
    {
        $line = $tree->filter(fn (Unit $unit) => $unit->isLine())->keyBy('callsign');
        $groups = $tree->reject(fn (Unit $unit) => $unit->isLine())->keyBy('callsign');
        $references = [];

        foreach ([[StaffBillet::PORTFOLIO_LINE_UNITS, $groups, $line], [StaffBillet::LINE_STAFF_GROUPS, $line, $groups]] as [$map, $parents, $children]) {
            foreach ($map as $parent => $callsigns) {
                foreach ($callsigns as $callsign) {
                    if ($parents->has($parent) && $children->has($callsign)) {
                        $references[] = ['parent_id' => $parents->get($parent)->id, 'unit_id' => $children->get($callsign)->id];
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Add a node under a chosen parent (AC #2). The Admin gate is enforced here, server-side,
     * regardless of what the UI shows (AC #5, AD-6), against `ScopeResolver::isAdmin()`: the `is_admin` flag or an approved Regimental HQ billet (AD-7).
     */
    public function store(Request $request, CreateUnit $command): RedirectResponse
    {
        // Admins add anywhere, Regimental HQ staff any line unit below the regiment; a leader adds a line unit inside the unit they command or oversee, at least two echelons
        // below it (ScopeResolver::mayAddUnit). Checked again after validation, once the parent is known.
        $adder = $request->user();
        if (! $this->scope->isAdmin($adder) && ! $this->scope->isRegimentalHqStaff($adder) && $this->scope->unitAddRoots($adder)->isEmpty()) {
            abort(403, 'You may not add units.');
        }

        // Only where the unit goes and which one it is: the server derives the name and callsign (CreateUnit), so a
        // request cannot hand it an arbitrary callsign. A name is read for a group only, which has no rule for one.
        $data = $request->validate([
            'type' => ['required', Rule::enum(UnitType::class)],
            'parent_id' => ['nullable', 'integer', 'exists:units,id'],
            'ordinal' => ['nullable', 'regex:/^([1-9]|[A-Za-z])$/'],
            'name' => ['nullable', 'string', 'max:255'],
        ], self::UNIT_MESSAGES + ['ordinal.regex' => 'Pick which unit this is from the list.']);

        $type = UnitType::from($data['type']);
        $parent = isset($data['parent_id']) ? $this->units->find((int) $data['parent_id']) : null;
        $allowed = $parent === null ? $this->scope->isAdmin($request->user()) : $this->scope->mayAddUnit($request->user(), $parent, $type);
        if (! $allowed) {
            abort(403, 'You may not add this unit here.');
        }

        try {
            $command->handle(
                $request->user(),
                $type,
                $data['parent_id'] ?? null,
                isset($data['ordinal']) ? (string) $data['ordinal'] : null,
                $type === UnitType::Group ? ($data['name'] ?? null) : null,
            );
        } catch (InvalidUnitPlacementException $e) {
            // Echelon ordering is a domain rule the request-shape validation above can't
            // express (it needs the parent's own type) — surfaced as a form error on `type`
            // rather than a 500, so the Inertia form's `errors.type` renders it.
            throw ValidationException::withMessages(['type' => $e->getMessage()]);
        } catch (InvalidUnitOrdinalException $e) {
            throw ValidationException::withMessages([$type === UnitType::Group ? 'name' : 'ordinal' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Rename a node and/or re-parent it (AC #1, #2). Echelon (`type`) isn't editable here — see
     * `UpdateUnit`'s docblock. `UpdateUnit` stages the re-parent through `MoveUnit` and the
     * rename in one transaction, each with its own audit row, so the edit applies fully or not
     * at all, and a rename with no `parent_id` change never touches `UnitRepository::move()`.
     */
    public function update(Request $request, int $unit, UpdateUnit $command): RedirectResponse
    {
        $unit = $this->adminUnit($request, $unit);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'callsign' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:units,id'],
        ], self::UNIT_MESSAGES);

        try {
            $command->handle($request->user(), $unit, $data['name'], $data['callsign'] ?? null, $data['parent_id'] ?? null);
        } catch (InvalidUnitPlacementException $e) {
            // Cycle and echelon-rank violations are domain rules the request-shape validation
            // above can't express — surfaced as a form error on `parent_id` (AC #3).
            throw ValidationException::withMessages(['parent_id' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Set or clear a unit's team label (2026-10-24). Admin-only, server-side (AD-6); audited by {@see SetUnitLabel}
     * (AD-16).
     */
    public function label(Request $request, int $unit, SetUnitLabel $command): RedirectResponse
    {
        $unit = $this->adminUnit($request, $unit);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:'.SetUnitLabel::MAX_LENGTH],
        ], ['label.max' => 'Keep the team label to :max characters.']);

        $command->handle($request->user(), $unit, $data['label'] ?? null);

        return redirect()->route('settings.org-structure');
    }

    /**
     * Set the game a unit plays from the Games list, or clear it so the unit inherits again. Server-side (AD-6): an
     * Admin or Regimental HQ staff on any unit, an overseer on the units they oversee and those under them, DEVCOM's 1IC
     * and 2IC on DEVCOM and the units under it (`ScopeResolver::maySetUnitGame()`); anyone else gets a 403, whether or
     * not the unit exists, when they could set no game anywhere. Audited by {@see SetUnitGame} (AD-16). A game that is
     * gone or retired, a new game under a unit that has one, or one over units with their own comes back as a form
     * error on `game_id`.
     */
    public function game(Request $request, int $unit, SetUnitGame $command): RedirectResponse
    {
        $user = $request->user();
        $anywhere = $this->scope->isAdmin($user) || $this->scope->isRegimentalHqStaff($user) || $this->scope->gameUnitsOf($user)->isNotEmpty();
        if (! $anywhere) {
            abort(403, 'Only an Admin, Regimental HQ or someone who oversees this unit may change its game.');
        }

        $unit = $this->units->find($unit) ?? abort(404);
        if (! $this->scope->maySetUnitGame($user, $unit)) {
            abort(403, 'Only an Admin, Regimental HQ or someone who oversees this unit may change its game.');
        }

        $data = $request->validate([
            'game_id' => ['nullable', 'integer'],
        ], ['game_id.integer' => 'Choose a game from the list.']);

        try {
            $command->handle($request->user(), $unit, isset($data['game_id']) ? (int) $data['game_id'] : null);
        } catch (InvalidUnitGameException $e) {
            throw ValidationException::withMessages(['game_id' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Make a serving trooper the acting lead of a unit the roster names no lead for (2026-10-25). Admin-only,
     * server-side (AD-6); audited with a required reason by {@see AssignActingLeadHandler} (AD-16).
     */
    public function assignActingLead(Request $request, int $unit, AssignActingLeadHandler $handler): RedirectResponse
    {
        $this->adminUnit($request, $unit);

        $data = $request->validate([
            'trooper_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'trooper_id.required' => 'Choose the trooper to make acting lead.',
            ...self::REASON_MESSAGES,
        ]);

        try {
            $handler->handle($request->user(), new AssignActingLead($unit, (int) $data['trooper_id'], $data['reason']));
        } catch (InvalidActingLeadException $e) {
            throw ValidationException::withMessages(['acting_lead' => $e->getMessage()]);
        } catch (ActingLeadForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Remove a unit's acting lead (2026-10-25). Admin-only (AD-6); audited with a required reason (AD-16).
     */
    public function removeActingLead(Request $request, int $unit, RemoveActingLeadHandler $handler): RedirectResponse
    {
        $this->adminUnit($request, $unit);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], self::REASON_MESSAGES);

        try {
            $handler->handle($request->user(), new RemoveActingLead($unit, $data['reason']));
        } catch (InvalidActingLeadException $e) {
            throw ValidationException::withMessages(['acting_lead' => $e->getMessage()]);
        } catch (ActingLeadForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Make a serving trooper the overseer of a battalion or the Reservists, replacing whoever was. Admin-only,
     * server-side (AD-6); audited with a required reason by {@see SetUnitOversightHandler}.
     */
    public function setOversight(Request $request, int $unit, SetUnitOversightHandler $handler): RedirectResponse
    {
        $this->adminUnit($request, $unit);

        $data = $request->validate([
            'trooper_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'trooper_id.required' => 'Choose the trooper who oversees this unit.',
            ...self::REASON_MESSAGES,
        ]);

        return $this->oversight($request, new SetUnitOversight($unit, (int) $data['trooper_id'], $data['reason']), $handler);
    }

    /**
     * Clear a unit's overseer. Admin-only (AD-6); audited with a required reason.
     */
    public function clearOversight(Request $request, int $unit, SetUnitOversightHandler $handler): RedirectResponse
    {
        $this->adminUnit($request, $unit);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], self::REASON_MESSAGES);

        return $this->oversight($request, new SetUnitOversight($unit, null, $data['reason']), $handler);
    }

    private function oversight(Request $request, SetUnitOversight $command, SetUnitOversightHandler $handler): RedirectResponse
    {
        try {
            $handler->handle($request->user(), $command);
        } catch (InvalidUnitOversightException $e) {
            throw ValidationException::withMessages(['oversight' => $e->getMessage()]);
        } catch (UnitOversightForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Remove a node (AC #4). Refused, not a raw DB constraint error, when children remain. An Admin removes any unit;
     * the leader who added one may undo the add within the hour while it is still empty.
     */
    public function destroy(Request $request, int $unit, DeleteUnit $command): RedirectResponse
    {
        // Anyone but an Admin may only undo adding a unit: their own add, within the hour, where they could still add it
        // (ScopeResolver::mayUndoAddUnit), and only while it is empty (DeleteUnit, under its lock).
        if (! $this->scope->isAdmin($request->user())) {
            $found = $this->units->find($unit);
            if ($found === null || ! $this->scope->mayUndoAddUnit($request->user(), $found)) {
                abort(403, UnitHasDependentsException::UNDO_WINDOW);
            }

            try {
                $command->handle($request->user(), $found, undoAdd: true);
            } catch (UnitHasDependentsException) {
                throw ValidationException::withMessages(['delete' => UnitHasDependentsException::UNDO_WINDOW]);
            }

            return redirect()->route('settings.org-structure');
        }

        $unit = $this->adminUnit($request, $unit);

        try {
            $command->handle($request->user(), $unit);
        } catch (UnitHasDependentsException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * The unit an edit names, behind the Admin gate. The gate comes first, so a non-Admin gets the same
     * 403 whether or not the id exists; only an Admin learns that an id is gone (404).
     */
    private function adminUnit(Request $request, int $id): Unit
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may change the org structure.');
        }

        return $this->units->find($id) ?? abort(404);
    }

    /**
     * Import the chain of command and the billet → section mapping from the Cav position groups
     * (story 2.9). Admin-only, server-side (AD-6). A failed import (Cav unreachable or unusable,
     * or no line companies) surfaces as an `errors.import` message on the page; nothing was
     * written in that case (the Command fetches and parses before it writes).
     */
    public function import(Request $request, ImportOrgStructure $command): RedirectResponse
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may change the org structure.');
        }

        try {
            $result = $command->handle($request->user());
        } catch (OrgStructureImportFailedException $e) {
            throw ValidationException::withMessages(['import' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure')->with('org_import', [
            'unitsCreated' => $result->unitsCreated,
            'mappingsCreated' => $result->mappingsCreated,
            'mappingsDiffering' => $result->mappingsDiffering,
            'mappingsRepointed' => $result->mappingsRepointed,
            'commandMappingsCreated' => $result->commandMappingsCreated,
            'commandMappingsRepointed' => $result->commandMappingsRepointed,
            'commandMappingsRemoved' => $result->commandMappingsRemoved,
            'skippedTitles' => $result->skippedTitles,
        ]);
    }
}
