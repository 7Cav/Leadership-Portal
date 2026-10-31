<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\FireteamAssignment;
use App\Models\FireteamSetting;
use App\Models\Game;
use App\Models\GameIdentityText;
use App\Models\GamePlayerDismissal;
use App\Models\GameServer;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\TrainingPlan;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitTemplateChoice;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AuditLogRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekLabel;
use Closure;
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

        $units = $tree->keyBy('id');
        $entries = $entries->through(fn (AuditLogEntry $entry) => [
            'id' => $entry->id,
            'actor' => $entry->actor->username,
            // Who an Admin was acting as when they did this (#379): a person ("acting as Vasquez.R"), or a billet
            // ("acting as Section Leader 2/1/A/1-7"; a unit deleted since reads "a unit since deleted").
            'onBehalfOf' => $entry->onBehalfOf?->username ?? match (true) {
                $entry->on_behalf_of_unit_id === null => null,
                default => $units->get($entry->on_behalf_of_unit_id)?->billet() ?? 'a unit since deleted',
            },
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
     * unit's roster, a trooper's milpac (also for their game identities, courses and placement, and for the portal user
     * they sign in as), a template (a company's or the regiment default), a section's template pick,
     * the staff grants, a platoon's training plan, a section's rollcall (for its fireteams and fireteam display), a platoon's report week (for its
     * training topic), the Games page (for a game), the Servers page (for a game server), or the rollcall week where a dismissed player was last captured. Null
     * when the target is gone or has no page.
     *
     * @return array{href: string, label: string}|null
     */
    private function link(?object $target): ?array
    {
        $unit = fn (?Unit $unit) => $unit?->callsign ?? $unit?->name;

        return match (true) {
            $target instanceof SectionPractice && $target->section !== null => [
                'href' => route('rollcall.show', ['unit' => $target->section_id, 'week' => $target->week_start->toDateString()], false),
                'label' => "{$unit($target->section)} roll call, week ".WeekLabel::range($target->week_start),
            ],
            $target instanceof WeeklyReport && $target->unit !== null => [
                'href' => route('reports.show', ['unit' => $target->unit_id, 'week' => $target->week_start->toDateString()], false),
                'label' => "{$unit($target->unit)} report, week ".WeekLabel::range($target->week_start),
            ],
            $target instanceof Unit => [
                'href' => route('roster.index', ['unit' => $target->id], false),
                'label' => $unit($target),
            ],
            $target instanceof Trooper => $this->trooperLink($target),
            ($target instanceof TrooperGameIdentity || $target instanceof TrooperCourse) && $target->trooper !== null => $this->trooperLink($target->trooper),
            $target instanceof TemplateVersion && $target->template !== null => [
                'href' => $this->templateHref($target->template),
                'label' => "{$this->templateLabel($target->template, $unit)}, version {$target->version}",
            ],
            $target instanceof Template => [
                'href' => $this->templateHref($target),
                'label' => $this->templateLabel($target, $unit),
            ],
            // A section's pick is made on its rollcall; a company's active template on the Templates page.
            $target instanceof UnitTemplateChoice && $target->unit?->type === UnitType::Section => [
                'href' => route('rollcall.show', ['unit' => $target->unit_id], false),
                'label' => "{$unit($target->unit)} roll call template",
            ],
            $target instanceof UnitTemplateChoice && $target->unit !== null => [
                'href' => route('settings.templates', ['company' => $target->unit_id], false),
                'label' => "{$unit($target->unit)} active template",
            ],
            $target instanceof PortalRoleGrant => [
                'href' => route('settings.staff-grants', [], false),
                'label' => 'Staff grants',
            ],
            $target instanceof FireteamAssignment && $target->section !== null => [
                'href' => route('rollcall.show', ['unit' => $target->section_id], false),
                'label' => "{$unit($target->section)} fireteams",
            ],
            // A section's own display choices live on its rollcall. A company's policy has no page of its own: it is
            // set from any of its sections' rollcalls, so it links nowhere.
            $target instanceof FireteamSetting && $target->unit?->type === UnitType::Section => [
                'href' => route('rollcall.show', ['unit' => $target->unit_id], false),
                'label' => "{$unit($target->unit)} fireteam display",
            ],
            $target instanceof PlatoonTrainingTopic && $target->unit !== null => [
                'href' => route('reports.show', ['unit' => $target->unit_id, 'week' => $target->week_start->toDateString()], false),
                'label' => "{$unit($target->unit)} training topic, week ".WeekLabel::range($target->week_start),
            ],
            $target instanceof TrainingPlan && $target->unit !== null => [
                'href' => route('training-plans.show', ['unit' => $target->unit_id, 'month' => $target->month], false),
                'label' => "{$unit($target->unit)} training plan, {$target->month}",
            ],
            $target instanceof Game => [
                'href' => route('settings.games', [], false),
                'label' => "{$target->name} game",
            ],
            $target instanceof GameServer => [
                'href' => route('settings.servers', [], false),
                'label' => "{$target->name} server",
            ],
            $target instanceof GamePlayerDismissal => $this->dismissalLink($target),
            // A user (a view-as target, a changed Admin flag) links to their trooper's milpac when they have one.
            $target instanceof User && $target->getRelationValue('trooper') !== null => $this->trooperLink($target->getRelationValue('trooper')),
            default => null,
        };
    }

    /**
     * `A/1-7 template "Training week"` (or the section's name for a section's own), or "Regiment default template" for
     * the one with no owner.
     *
     * @param  Closure(?Unit): ?string  $unit
     */
    private function templateLabel(Template $template, Closure $unit): string
    {
        return $template->isRegimentDefault()
            ? 'Regiment default template'
            : "{$unit($template->section ?? $template->company)} template \"{$template->name}\"";
    }

    /** The Templates page open on the template: its company's, or its section's for a section's own. */
    private function templateHref(Template $template): string
    {
        $owner = $template->isSectionTemplate() ? ['section' => $template->section_id] : ['company' => $template->company_id];

        return route('settings.templates', array_filter([...$owner, 'template' => $template->id]), false);
    }

    /**
     * A dismissal belongs to no unit, so it links to the rollcall week of the latest practice that captured the player,
     * where it is listed with its undo. Null when no stored capture saw the player.
     *
     * @return array{href: string, label: string}|null
     */
    private function dismissalLink(GamePlayerDismissal $dismissal): ?array
    {
        $practice = $dismissal->getRelationValue('latestCapture')?->practice;

        if ($practice?->section === null) {
            return null;
        }

        $section = $practice->section->callsign ?? $practice->section->name;

        return [
            'href' => route('rollcall.show', ['unit' => $practice->section_id, 'week' => $practice->week_start->toDateString()], false),
            'label' => GameIdentityText::reveal($dismissal->player_name)." dismissed, {$section} roll call, week ".WeekLabel::range($practice->week_start),
        ];
    }

    /**
     * @return array{href: string, label: string}
     */
    private function trooperLink(Trooper $trooper): array
    {
        return ['href' => route('troopers.show', ['trooper' => $trooper->id], false), 'label' => $trooper->display_name];
    }
}
