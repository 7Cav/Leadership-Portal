<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\WeeklyReports\InvalidReportContentException;
use App\Commands\WeeklyReports\SaveReportDraft;
use App\Commands\WeeklyReports\SaveReportDraftHandler;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\ChildReport;
use App\Kpi\ChildReportStatus;
use App\Kpi\KpiDelta;
use App\Kpi\KpiService;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\ReportSnapshot;
use App\Kpi\RollupKpis;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Kpi\WeeklyReportKpis;
use App\Milpac\RosterProvenance;
use App\Models\Attendance;
use App\Models\Trooper;
use App\Models\UnaccountedReason;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use ErrorException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use TypeError;

/**
 * The weekly report compose screen (5.3–5.5, binding design: mockups/v2/report-p3.html). One
 * unit-week: a section's draft computed live from attendance (AD-12) with its readiness facts,
 * a non-leaf unit's roll-up of its children's sent snapshots (5.7), or — once sent — the frozen
 * snapshot, read back as stored and never recomputed. Writes go through the audited
 * `SaveReportDraft` and `SendWeeklyReport` Commands.
 *
 * Every unit is checked against `ScopeResolver::resolve()` (AD-8, narrowed by view-as, AD-9): a
 * unit outside it is a 403, whether or not it exists.
 */
class ReportController extends Controller
{
    /** How many earlier sent reports the history and trend read. */
    private const HISTORY_WEEKS = 12;

    /** The trend chart's span, this week included. */
    private const TREND_WEEKS = 8;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly WeeklyReportKpis $snapshots,
        private readonly KpiService $kpis,
        private readonly RollupKpis $rollups,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly RosterProvenance $rosterSync,
        private readonly UserRepository $users,
    ) {}

    public function show(Request $request, int $unit, string $week): Response
    {
        $unit = $this->unitInScope($request, $unit);
        $weekStart = $this->weekStart($week);
        $report = $this->reports->find($unit->id, $weekStart);
        $sent = $report?->status === WeeklyReportStatus::Sent;
        $history = $this->reports->sentBefore($unit->id, $weekStart, self::HISTORY_WEEKS);
        $isLeaf = $unit->type === UnitType::Section;
        $children = $isLeaf ? collect() : $this->units->children($unit);

        $snapshot = null;
        $kpiError = null;

        if ($sent) {
            // A sent report is read back as frozen, never recomputed (AD-12).
            $snapshot = $this->frozen($report);
            $kpiError = $snapshot === null ? "This report's frozen snapshot cannot be read. An admin needs to look at it." : null;
        } else {
            try {
                $snapshot = $this->snapshots->snapshot($unit, $weekStart);
            } catch (ReportKpisUnavailableException $e) {
                $kpiError = $this->unavailableMessage($e);
            }
        }

        $roster = $isLeaf ? $this->troopers->rosterOf($unit) : collect();
        $parent = $unit->parent_id === null ? null : $this->units->find($unit->parent_id);
        $lastSync = $this->rosterSync->current();
        $senders = $this->senderLabels(collect([$report])->merge($history)->filter(fn (?WeeklyReport $r) => $r?->status === WeeklyReportStatus::Sent));

        return Inertia::render('Reports/Show', [
            'unit' => [
                ...$this->unitProps($unit),
                'isLeaf' => $isLeaf,
                'parent' => $parent === null ? null : $this->unitProps($parent),
            ],
            'week' => [
                'start' => $weekStart->toIso8601String(),
                'due' => WeekOf::reportDue($weekStart)->toIso8601String(),
                'previous' => $weekStart->subWeek()->toDateString(),
                'next' => $weekStart->addWeek()->lessThanOrEqualTo($this->currentWeek()) ? $weekStart->addWeek()->toDateString() : null,
                // Older than the week floor: shown read-only, a draft or send for it is a 404.
                'beforeFloor' => WeekOf::isBeforeFloor($weekStart),
            ],
            'report' => $this->reportProps($report, $senders),
            // Whether this viewer may save or send it: command authority, not a staff grant, which only reads.
            'canWrite' => $this->scope->writeScope($request->user())->contains($unit->id),
            'kpis' => $snapshot === null ? null : $this->kpiProps($snapshot->kpis),
            'delta' => $snapshot?->delta === null ? null : $this->deltaProps($snapshot->delta),
            'kpiError' => $kpiError,
            'baseline' => $this->baseline($snapshot, $sent ? $report : null, $history, $weekStart),
            'trend' => $this->trend($weekStart, $snapshot, $history),
            'troopers' => $isLeaf && ! $sent ? $this->trooperRows($unit, $weekStart, $roster) : null,
            // An object even when empty: `[]` fails the page's Object prop check.
            'names' => (object) $roster->mapWithKeys(fn (Trooper $t) => [$t->id => $this->trooperLabel($t)])->all(),
            'children' => match (true) {
                $isLeaf => null,
                // A damaged snapshot cannot say who was in it.
                $sent => $snapshot === null ? null : $this->frozenChildRows($children, $snapshot->missingChildren),
                default => $this->childRows($unit, $weekStart, $children),
            },
            'missingChildren' => $snapshot?->missingChildren ?? [],
            'history' => $history->map(fn (WeeklyReport $past) => $this->historyRow($past, $senders))->values(),
            'reasons' => collect(UnaccountedReason::cases())->map(fn (UnaccountedReason $r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'rosterSync' => ['source' => $lastSync->source, 'at' => $lastSync->lastSyncedAt?->toIso8601String()],
            'computedAt' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
    }

    public function draft(Request $request, SaveReportDraftHandler $handler, int $unit, string $week): RedirectResponse
    {
        $unit = $this->unitInScope($request, $unit);
        $weekStart = $this->writableWeekStart($week);
        $data = $request->validate($this->contentRules());

        $this->asFormErrors(fn () => $handler->handle($request->user(), new SaveReportDraft(
            $unit->id,
            $weekStart,
            $data['notes'] ?? null,
            $data['training_subject'] ?? null,
            $data['unaccounted'] ?? [],
        )));

        return redirect()->route('reports.show', ['unit' => $unit->id, 'week' => $weekStart->toDateString()]);
    }

    public function send(Request $request, SendWeeklyReportHandler $handler, int $unit, string $week): RedirectResponse
    {
        $unit = $this->unitInScope($request, $unit);
        $weekStart = $this->writableWeekStart($week);
        $data = $request->validate([...$this->contentRules(), 'reason' => ['required', 'string', 'max:1000']]);

        $result = $this->asFormErrors(fn () => $handler->handle($request->user(), new SendWeeklyReport(
            $unit->id,
            $weekStart,
            $data['reason'],
            $data['notes'] ?? null,
            $data['training_subject'] ?? null,
            $data['unaccounted'] ?? [],
        )));

        // Already frozen (an earlier or concurrent send): the snapshot stands as it was, so the
        // leader learns that what they just confirmed was not what got frozen.
        if ($result->alreadySent) {
            throw ValidationException::withMessages(['report' => 'This report was already sent. It is shown as it was frozen; your latest changes were not saved.']);
        }

        return redirect()->route('reports.show', ['unit' => $unit->id, 'week' => $weekStart->toDateString()]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function contentRules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],
            'training_subject' => ['nullable', 'string', 'max:255'],
            'unaccounted' => ['nullable', 'array', 'max:200'],
            'unaccounted.*.trooper_id' => ['required', 'integer'],
            'unaccounted.*.reason' => ['required', 'string'],
        ];
    }

    /**
     * A Command's refusal that the leader can act on becomes a form error on the page; an
     * out-of-scope refusal stays an `AuthorizationException` (403).
     */
    private function asFormErrors(callable $write): mixed
    {
        try {
            return $write();
        } catch (InvalidReportContentException) {
            // Most often a trooper moved out of the section since the page loaded.
            throw ValidationException::withMessages(['unaccounted' => 'A trooper you gave a reason for is no longer in this section, or the reason is not one of the listed ones. Reload the page to update the roster.']);
        } catch (ReportKpisUnavailableException $e) {
            throw ValidationException::withMessages(['report' => $this->unavailableMessage($e)]);
        } catch (WeeklyReportAlreadySentException) {
            throw ValidationException::withMessages(['report' => 'This report has already been sent and can no longer be edited.']);
        }
    }

    /**
     * Leader-facing wording for why the numbers cannot be computed. A damaged snapshot, last
     * week's or a child's, is corrupt data an operator must hear about, so it is reported as well.
     */
    private function unavailableMessage(ReportKpisUnavailableException $e): string
    {
        if ($e->getPrevious() === null) {
            return 'This unit has no units under it, so there are no reports to roll up.';
        }

        report($e);

        if ($e->unreadable !== null) {
            $child = $this->units->find($e->unreadable->unit_id);
            $name = $child === null ? 'A unit under this one' : ($child->callsign ?? $child->name);

            return "{$name}'s sent report cannot be read, so this roll-up cannot be computed. An admin needs to look at it.";
        }

        return "Last week's sent report cannot be read, so this week's numbers cannot be compared with it. An admin needs to look at it.";
    }

    private function unitInScope(Request $request, int $unitId): Unit
    {
        $unit = $this->scope->resolve($request->user())->contains($unitId) ? $this->units->find($unitId) : null;

        if ($unit === null) {
            abort(403, 'That unit is outside your scope.');
        }

        return $unit;
    }

    /** `YYYY-MM-DD` of a Saturday that opens a Cav week no later than the current one (AD-11). */
    private function weekStart(string $week): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC');

        if ($date === false || $date->toDateString() !== $week || ! WeekOf::start($date)->equalTo($date) || $date->greaterThan($this->currentWeek())) {
            abort(404);
        }

        return $date;
    }

    /** {@see weekStart()} for a write: a week before `WeekOf::floor()` is a 404 too. */
    private function writableWeekStart(string $week): CarbonImmutable
    {
        $weekStart = $this->weekStart($week);
        abort_if(WeekOf::isBeforeFloor($weekStart), 404);

        return $weekStart;
    }

    private function currentWeek(): CarbonImmutable
    {
        return WeekOf::start(CarbonImmutable::now('UTC'));
    }

    /**
     * @return array{id: int, name: string, callsign: ?string, type: string}
     */
    private function unitProps(Unit $unit): array
    {
        return ['id' => $unit->id, 'name' => $unit->name, 'callsign' => $unit->callsign, 'type' => $unit->type->value];
    }

    /**
     * @param  Collection<int, string>  $senders  user id => `RANK Name`
     * @return array<string, mixed>
     */
    private function reportProps(?WeeklyReport $report, Collection $senders): array
    {
        return [
            'status' => $report?->status->value ?? 'none',
            'notes' => $report?->notes,
            'trainingSubject' => $report?->training_subject,
            'unaccounted' => $report?->unaccounted ?? [],
            'savedAt' => $report?->updated_at?->toIso8601String(),
            'sentAt' => $report?->sent_at?->toIso8601String(),
            'sentBy' => $report?->sent_by === null ? null : $senders->get($report->sent_by),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpiProps(UnitKpis $kpis): array
    {
        return [
            'strength' => $kpis->strength,
            'rc' => ['numerator' => $kpis->rc30d->numerator, 'denominator' => $kpis->rc30d->denominator],
            'sp' => ['numerator' => $kpis->sp30d->numerator, 'denominator' => $kpis->sp30d->denominator],
            'atRisk' => $kpis->atRisk,
            'onLoa' => $kpis->onLoa,
            'makeups' => $kpis->makeups,
        ];
    }

    /**
     * @return array<string, ?int>
     */
    private function deltaProps(KpiDelta $delta): array
    {
        return [
            'strength' => $delta->strength,
            'rcBp' => $delta->rc30dBp,
            'spBp' => $delta->sp30dBp,
            'atRisk' => $delta->atRisk,
            'onLoa' => $delta->onLoa,
            'makeups' => $delta->makeups,
        ];
    }

    /**
     * What the deltas compare against (5.4): the prior week's sent snapshot; or, when that week
     * was not sent, the most recent earlier sent week ("no prior baseline"); or nothing at all —
     * the unit's first report. For a sent report this is what was true when it froze: a week
     * sent late, after it, never changes how it reads.
     *
     * @param  Collection<int, WeeklyReport>  $history
     * @return array{kind: string, week: ?string}
     */
    private function baseline(?ReportSnapshot $snapshot, ?WeeklyReport $sent, Collection $history, CarbonImmutable $weekStart): array
    {
        if ($snapshot?->delta !== null) {
            return ['kind' => 'prior', 'week' => $weekStart->subWeek()->toIso8601String()];
        }

        $latest = $history->first(fn (WeeklyReport $past) => $sent === null || $past->sent_at?->lessThan($sent->sent_at));

        return $latest === null
            ? ['kind' => 'first', 'week' => null]
            : ['kind' => 'no_prior', 'week' => $latest->week_start->toIso8601String()];
    }

    /**
     * RC/SP over the last {@see TREND_WEEKS} weeks: each earlier week as it was sent (a week with
     * no sent report is a gap, never estimated), and this week as shown.
     *
     * @param  Collection<int, WeeklyReport>  $history
     * @return list<array<string, mixed>>
     */
    private function trend(CarbonImmutable $weekStart, ?ReportSnapshot $snapshot, Collection $history): array
    {
        $byWeek = $history->keyBy(fn (WeeklyReport $r) => $r->week_start->toDateString());
        $points = [];

        for ($back = self::TREND_WEEKS - 1; $back >= 0; $back--) {
            $week = $weekStart->subWeeks($back);
            $kpis = $back === 0 ? $snapshot?->kpis : $this->frozenKpis($byWeek->get($week->toDateString()));

            $points[] = [
                'week' => $week->toIso8601String(),
                'current' => $back === 0,
                'rc' => $kpis === null ? null : ['numerator' => $kpis->rc30d->numerator, 'denominator' => $kpis->rc30d->denominator],
                'sp' => $kpis === null ? null : ['numerator' => $kpis->sp30d->numerator, 'denominator' => $kpis->sp30d->denominator],
            ];
        }

        return $points;
    }

    /** A sent report's frozen KPIs, or null when there is none or it cannot be read. */
    private function frozenKpis(?WeeklyReport $report): ?UnitKpis
    {
        return $report === null ? null : $this->frozen($report)?->kpis;
    }

    private function frozen(WeeklyReport $report): ?ReportSnapshot
    {
        try {
            return ReportSnapshot::fromArray($report->kpis);
        } catch (InvalidArgumentException|TypeError|ErrorException $e) {
            // A corrupt sent row is shown as unreadable rather than breaking the page, and reported
            // so the corruption does not go unnoticed.
            report($e);

            return null;
        }
    }

    /**
     * Each assigned trooper's facts for the week, as the readiness checklist and the preview's
     * roster read them: rollcall signature, SP mark and its source (AD-20), and the shared
     * service's at-risk/LOA/missed reading (AD-21).
     *
     * @param  Collection<int, Trooper>  $roster
     * @return list<array<string, mixed>>
     */
    private function trooperRows(Unit $section, CarbonImmutable $weekStart, Collection $roster): array
    {
        $practice = $this->practices->find($section->id, $weekStart);
        $marks = $practice === null ? collect() : $this->attendance->forPractice($practice)->keyBy('trooper_id');
        $kpis = $this->kpis->trooperAttendance($section, $weekStart);

        return $roster->map(function (Trooper $trooper) use ($marks, $kpis) {
            /** @var Attendance|null $mark */
            $mark = $marks->get($trooper->id);
            /** @var TrooperAttendance|null $reading */
            $reading = $kpis->get($trooper->id);

            return [
                'id' => $trooper->id,
                'name' => $this->trooperLabel($trooper),
                'username' => $trooper->username,
                'rankShort' => $trooper->rank_short,
                'rollcall' => $mark?->rollcall->value ?? 'unsigned',
                'status' => $mark?->status?->value,
                'source' => $mark?->source?->value,
                'atRisk' => $reading?->atRisk() ?? false,
                'missed' => $reading->missed ?? 0,
                'onLoa' => $reading->onLoa ?? false,
            ];
        })->values()->all();
    }

    /**
     * A sent roll-up's children as it froze them: in the total, or missing at send. Their live
     * status and numbers now would contradict the frozen total (AD-12, AD-13).
     *
     * @param  Collection<int, Unit>  $children
     * @param  list<int>  $missing
     * @return list<array<string, mixed>>
     */
    private function frozenChildRows(Collection $children, array $missing): array
    {
        return $children->map(fn (Unit $child) => [
            ...$this->unitProps($child),
            'status' => in_array($child->id, $missing, true) ? 'missing' : 'included',
            'kpis' => null,
            'sentAt' => null,
            'trainingSubject' => null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Unit>  $children
     * @return list<array<string, mixed>>
     */
    private function childRows(Unit $unit, CarbonImmutable $weekStart, Collection $children): array
    {
        if ($children->isEmpty()) {
            return [];
        }

        $reports = $this->reports->forUnits($children->modelKeys(), $weekStart);

        try {
            $rows = collect($this->rollups->forUnit($unit, $weekStart)->children)
                ->map(fn (ChildReport $child) => ['unit' => $child->unit, 'status' => $child->status->value, 'kpis' => $child->kpis]);
        } catch (ReportKpisUnavailableException) {
            // A child's sent snapshot cannot be read (the page's `kpiError` already says so and
            // reported it): every child is still listed, the unreadable one with no numbers.
            $rows = $children->map(fn (Unit $child) => [
                'unit' => $child,
                'status' => match ($reports->get($child->id)?->status) {
                    WeeklyReportStatus::Sent => ChildReportStatus::Sent->value,
                    WeeklyReportStatus::Draft => ChildReportStatus::Draft->value,
                    null => ChildReportStatus::NotStarted->value,
                },
                'kpis' => $this->readableKpis($reports->get($child->id)),
            ]);
        }

        return $rows->map(fn (array $row) => [
            ...$this->unitProps($row['unit']),
            'status' => $row['status'],
            'kpis' => $row['kpis'] === null ? null : $this->kpiProps($row['kpis']),
            'sentAt' => $reports->get($row['unit']->id)?->sent_at?->toIso8601String(),
            'trainingSubject' => $reports->get($row['unit']->id)?->training_subject,
        ])->values()->all();
    }

    /** A sent report's frozen KPIs, or null when it is not sent or cannot be read (reported by the caller). */
    private function readableKpis(?WeeklyReport $report): ?UnitKpis
    {
        if ($report?->status !== WeeklyReportStatus::Sent) {
            return null;
        }

        try {
            return UnitKpis::fromArray($report->kpis);
        } catch (InvalidArgumentException|TypeError|ErrorException) {
            return null;
        }
    }

    /**
     * @param  Collection<int, string>  $senders
     * @return array<string, mixed>
     */
    private function historyRow(WeeklyReport $report, Collection $senders): array
    {
        $snapshot = $this->frozen($report);

        return [
            'week' => $report->week_start->toIso8601String(),
            'sentAt' => $report->sent_at?->toIso8601String(),
            'sentBy' => $senders->get($report->sent_by),
            'kpis' => $snapshot === null ? null : $this->kpiProps($snapshot->kpis),
            'delta' => $snapshot?->delta === null ? null : $this->deltaProps($snapshot->delta),
            'missingChildren' => $snapshot->missingChildren ?? [],
            'notes' => $report->notes,
            'trainingSubject' => $report->training_subject,
            'unaccounted' => $report->unaccounted ?? [],
        ];
    }

    /**
     * `RANK Name` for each sender: the roster name of the trooper their account is linked to, or
     * their forum username when the roster has none.
     *
     * @param  Collection<int, WeeklyReport>  $sent
     * @return Collection<int, string> user id => label
     */
    private function senderLabels(Collection $sent): Collection
    {
        $users = $sent->map(fn (WeeklyReport $r) => $r->relationLoaded('sender') ? $r->sender : $this->users->find($r->sent_by))
            ->filter()
            ->unique('id');
        $troopers = $this->troopers->byCavId($users->pluck('cav_id')->filter()->values()->all());

        return $users->mapWithKeys(fn (User $user) => [
            $user->id => $troopers->get($user->cav_id)?->display_name ?? $user->username,
        ]);
    }

    /** `SGT Vasquez.R` — the roster's own rendering of a trooper (2.10). */
    private function trooperLabel(Trooper $trooper): string
    {
        return $trooper->display_name;
    }
}
