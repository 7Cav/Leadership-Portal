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
use App\Commands\Units\InvalidUnitOversightException;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Commands\Units\OrgStructureImportFailedException;
use App\Commands\Units\RemoveActingLead;
use App\Commands\Units\RemoveActingLeadHandler;
use App\Commands\Units\SetUnitLabel;
use App\Commands\Units\SetUnitOversight;
use App\Commands\Units\SetUnitOversightHandler;
use App\Commands\Units\StaffBillet;
use App\Commands\Units\UnitHasDependentsException;
use App\Commands\Units\UnitOversightForbiddenException;
use App\Commands\Units\UpdateUnit;
use App\Http\Controllers\Controller;
use App\Kpi\Headcount;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitOversight;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
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
    public function index(Request $request, ScopeResolver $scope, UnitGuidonRepository $guidons, Headcount $headcount, UnitOversightRepository $oversights): Response
    {
        $tree = $this->units->tree();
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

        $units = $tree->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'parent_id' => $unit->parent_id,
            'type' => $unit->type->value,
            'kind' => ($unit->kind ?? UnitKind::Line)->value,
            'name' => $unit->name,
            'callsign' => $unit->callsign,
            'label' => $unit->label,
            // The unit's saved guidon (#446), drawn next to its name; null when it has none.
            'guidon' => $guidonOf->get($unit->id)?->design(),
            'path' => $unit->path,
            'own_count' => $byUnit->get($unit->id, 0),
            'trooper_count' => $byUnit
                ->filter(fn (int $count, int $id) => str_starts_with($placedIn->get($id)->path, $unit->path)
                    && ($placedIn->get($id)->kind !== UnitKind::Recruit || $unit->kind === UnitKind::Recruit))
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
            'empty' => ! $withTroopers->has($unit->id),
            // Who oversees the unit (decided by the S6, 2026-10-29): a battalion or the Reservists. A battalion with no
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
            // Rows shown in a second place without moving the unit (decided by the S6, 2026-10-29): ACD and DEVCOM under
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
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may change the org structure.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::enum(UnitType::class)],
            'name' => ['required', 'string', 'max:255'],
            'callsign' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:units,id'],
        ], self::UNIT_MESSAGES);

        try {
            $command->handle(
                $request->user(),
                UnitType::from($data['type']),
                $data['name'],
                $data['callsign'] ?? null,
                $data['parent_id'] ?? null,
            );
        } catch (InvalidUnitPlacementException $e) {
            // Echelon ordering is a domain rule the request-shape validation above can't
            // express (it needs the parent's own type) — surfaced as a form error on `type`
            // rather than a 500, so the Inertia form's `errors.type` renders it.
            throw ValidationException::withMessages(['type' => $e->getMessage()]);
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
     * Set or clear a unit's game or team label (2026-10-24, DEVCOM's "WARDOGS"). Admin-only, server-side (AD-6);
     * audited by {@see SetUnitLabel} (AD-16).
     */
    public function label(Request $request, int $unit, SetUnitLabel $command): RedirectResponse
    {
        $unit = $this->adminUnit($request, $unit);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:'.SetUnitLabel::MAX_LENGTH],
        ], ['label.max' => 'Keep the game or team label to :max characters.']);

        $command->handle($request->user(), $unit, $data['label'] ?? null);

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
     * Make a serving trooper the overseer of a battalion or the Reservists, replacing whoever was (decided by the S6,
     * 2026-10-29). Admin-only, server-side (AD-6); audited with a required reason by {@see SetUnitOversightHandler}.
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
     * Clear a unit's overseer (decided by the S6, 2026-10-29). Admin-only (AD-6); audited with a required reason.
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
     * Remove a node (AC #4). Refused, not a raw DB constraint error, when children remain.
     */
    public function destroy(Request $request, int $unit, DeleteUnit $command): RedirectResponse
    {
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
