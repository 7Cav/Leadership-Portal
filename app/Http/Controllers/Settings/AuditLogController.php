<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Repositories\AuditLogRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin audit-log review (Story 1.14, AD-16). Reads `AuditLogRepository` directly per the slim,
 * Laravel-idiomatic layer model (mirrors `StaffGrantsController`). Unlike `StaffGrants`, viewing
 * itself is Admin-only (AC #4) — there is no read/write split here, so the gate covers the whole
 * action.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogRepository $auditLog,
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    public function index(Request $request): Response
    {
        // `actsAsAdmin`, not the raw flag: an Admin previewing a leader (view-as, Story 1.13)
        // sees what that leader would — no audit log.
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may review the audit log.');
        }

        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:255'],
            'target_type' => ['nullable', 'string', 'max:255'],
            'target_id' => ['nullable', 'string', 'max:255'],
            // `date_format:Y-m-d`, not the looser `date` rule — `date` accepts anything
            // `strtotime()` parses (e.g. "now", "+1 day"), which `AuditLogRepository::filtered()`
            // would then hand straight to the query, silently returning an empty result instead
            // of failing loudly. `Y-m-d` matches what the `<input type="date">` controls emit.
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            // The entries about this unit or anything under it.
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $tree = $this->units->tree();

        $entries = $this->auditLog->filtered(
            actorId: $filters['actor_id'] ?? null,
            action: $filters['action'] ?? null,
            targetType: $filters['target_type'] ?? null,
            targetId: $filters['target_id'] ?? null,
            dateFrom: $filters['date_from'] ?? null,
            dateTo: $filters['date_to'] ?? null,
            withinUnit: isset($filters['unit_id']) ? $tree->firstWhere('id', (int) $filters['unit_id']) : null,
        );

        // What the page's entries changed, read once for the whole page (one query per target type), so each entry can
        // link to it without a query of its own.
        $targets = $this->auditLog->targetsOf($entries->items());

        $entries = $entries->through(fn (AuditLogEntry $entry) => [
            'id' => $entry->id,
            'actor' => $entry->actor->username,
            'action' => $entry->action,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            'link' => $this->link($targets["{$entry->target_type}#{$entry->target_id}"] ?? null),
            'reason' => $entry->reason,
            'content_digest' => $entry->content_digest,
            'created_at' => $entry->created_at->toIso8601String(),
        ])
            // Without this, `next_page_url`/`prev_page_url` carry no query string (Laravel only
            // appends one that's been set explicitly), so paging silently drops every filter and
            // the Admin ends up looking at an unfiltered page while the form still shows the
            // filters they set.
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return Inertia::render('Settings/AuditLog', [
            'entries' => $entries,
            'filters' => $filters,
            'actorOptions' => $this->auditLog->actors()->map(fn ($user) => [
                'id' => $user->id,
                'username' => $user->username,
            ])->values(),
            'actionOptions' => $this->auditLog->actions()->values(),
            // Every unit, in tree order, for the unit filter.
            'unitOptions' => $tree->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'label' => $unit->callsign ? "{$unit->callsign} {$unit->name}" : $unit->name,
                'depth' => max(0, substr_count($unit->path, '/') - 1),
            ])->values(),
        ]);
    }

    /**
     * Where to see what an entry changed, and what to call it: a section practice's rollcall week, a weekly report, a
     * unit's roster, a trooper's milpac (also for their game identities, courses and placement), a company's templates,
     * or the staff grants. Null when the target is gone or has no page.
     *
     * @return array{href: string, label: string}|null
     */
    private function link(?object $target): ?array
    {
        $unit = fn (?Unit $unit) => $unit?->callsign ?? $unit?->name;

        return match (true) {
            $target instanceof SectionPractice && $target->section !== null => [
                'href' => route('rollcall.show', ['unit' => $target->section_id, 'week' => $target->week_start->toDateString()], false),
                'label' => "{$unit($target->section)} rollcall, week of {$target->week_start->format('dMy')}",
            ],
            $target instanceof WeeklyReport && $target->unit !== null => [
                'href' => route('reports.show', ['unit' => $target->unit_id, 'week' => $target->week_start->toDateString()], false),
                'label' => "{$unit($target->unit)} report, week of {$target->week_start->format('dMy')}",
            ],
            $target instanceof Unit => [
                'href' => route('roster.index', ['unit' => $target->id], false),
                'label' => $unit($target),
            ],
            $target instanceof Trooper => $this->trooperLink($target),
            ($target instanceof TrooperGameIdentity || $target instanceof TrooperCourse) && $target->trooper !== null => $this->trooperLink($target->trooper),
            $target instanceof TemplateVersion && $target->company !== null => [
                'href' => route('settings.templates', ['company' => $target->company_id], false),
                'label' => "{$unit($target->company)} template, version {$target->version}",
            ],
            $target instanceof PortalRoleGrant => [
                'href' => route('settings.staff-grants', [], false),
                'label' => 'Staff grants',
            ],
            default => null,
        };
    }

    /**
     * @return array{href: string, label: string}
     */
    private function trooperLink(Trooper $trooper): array
    {
        return ['href' => route('troopers.show', ['trooper' => $trooper->id], false), 'label' => $trooper->display_name];
    }
}
