<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\ChainNode;
use App\Kpi\ChildReport;
use App\Kpi\KpiDelta;
use App\Kpi\ReportChain;
use App\Kpi\UnitKpis;
use App\Models\Unit;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The report roll-up workspace (Story 5.8, mockups/v2/report-p2.html): the accordion tree of the
 * viewer's scope for one Cav week, each unit with its send status and KPIs, for the leaders who
 * review the chain. Read-only — composing, saving and sending a report is `reports.show`'s screen,
 * which every node links to by URL.
 *
 * Scope comes from the ScopeResolver on every request (AD-8; view-as narrows it, AD-9). The
 * numbers come from `ReportChain`, which batches a whole chain into a constant number of queries
 * and composes the shared KPI pieces (AD-21) rather than deriving any here.
 */
class ReportRollupController extends Controller
{
    /** How many past weeks the week selector offers besides the current one. */
    private const PAST_WEEKS = 12;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly UserRepository $users,
        private readonly ReportChain $chain,
    ) {}

    public function index(Request $request, ?int $unit = null): Response|RedirectResponse
    {
        $current = WeekOf::reporting(CarbonImmutable::now());
        $week = $this->requestedWeek($request, $current);
        $scope = $this->scope->resolve($request->user());

        if ($unit !== null) {
            $root = $this->units->find($unit);
            abort_if($root === null, 404);
            abort_unless($scope->contains($root->id), 403);
            abort_unless($root->isLine(), 404);
            $roots = collect([$root]);
        } else {
            $roots = $this->topmost($scope);
        }

        // A leaf has no chain to review: its leader lands straight on their own report.
        if ($roots->count() === 1 && ! $this->units->hasLineChildren($roots->first())) {
            return redirect('/reports/'.$roots->first()->id.'/'.$week->toDateString());
        }

        $chains = $roots->map(fn (Unit $root) => $this->chain->build($root, $week));
        $senders = $this->users->usernamesByIds($chains->flatMap(fn (ChainNode $node) => self::senderIds($node))->unique()->values()->all());

        return Inertia::render('Reports/Rollup', [
            'week' => [
                'start' => $week->toIso8601String(),
                'reportDue' => WeekOf::reportDue($week)->toIso8601String(),
                'isCurrent' => $week->equalTo($current),
                'options' => collect(range(0, self::PAST_WEEKS))
                    ->map(fn (int $back) => $current->subWeeks($back)->toDateString())
                    ->all(),
            ],
            'scopeUnitId' => $unit,
            'roots' => $chains->map(fn (ChainNode $node) => $this->present($node, $week, $senders))->values()->all(),
        ]);
    }

    /**
     * `?week=YYYY-MM-DD`: a week boundary among the ones the selector offers — the current week and
     * the {@see PAST_WEEKS} before it; absent means current. Anything else, an overflowing date
     * such as `2026-02-35` included, is a page that does not exist rather than a silently snapped
     * week.
     */
    private function requestedWeek(Request $request, CarbonImmutable $current): CarbonImmutable
    {
        $param = $request->query('week');

        if ($param === null) {
            return $current;
        }

        abort_unless(is_string($param) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $param) === 1, 404);

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $param, 'UTC');
        } catch (InvalidFormatException) {
            abort(404);
        }

        abort_unless($date->toDateString() === $param, 404);

        try {
            $week = WeekOf::requireStart($date);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($week->greaterThan($current) || $week->lessThan($current->subWeeks(self::PAST_WEEKS)), 404);

        return $week;
    }

    /**
     * The highest units of the scope: those whose parent is outside it. Scope is subtree-closed,
     * so every other unit in it sits under one of these.
     *
     * @param  Collection<int, int>  $scope
     * @return Collection<int, Unit>
     */
    private function topmost(Collection $scope): Collection
    {
        $ids = $scope->flip();

        return $this->units->tree()
            ->filter(fn (Unit $unit) => $unit->isLine() && $ids->has($unit->id) && ($unit->parent_id === null || ! $ids->has($unit->parent_id)))
            ->sortBy('id')
            ->values()
            ->toBase();
    }

    /** @return list<int> */
    private static function senderIds(ChainNode $node): array
    {
        $ids = array_values(array_filter([$node->report?->sent_by, $node->report?->sent_by_admin]));

        foreach ($node->children as $child) {
            array_push($ids, ...self::senderIds($child));
        }

        return $ids;
    }

    /**
     * @param  Collection<int, string>  $senders
     * @return array<string, mixed>
     */
    private function present(ChainNode $node, CarbonImmutable $week, Collection $senders): array
    {
        return [
            'id' => $node->unit->id,
            'name' => $node->unit->name,
            'callsign' => $node->unit->callsign,
            'type' => $node->unit->type->value,
            'status' => $node->status->value,
            'holding' => $node->isHolding(),
            'open' => $node->open,
            'live' => $node->live,
            // Null when a sent snapshot it depends on cannot be read: the row shows it as unavailable.
            'kpis' => $node->kpis === null ? null : self::kpis($node->kpis),
            'delta' => self::delta($node->delta),
            'coverage' => $node->rollup === null ? null : [
                'sent' => count($node->rollup->children) - count($node->rollup->missingChildren()),
                'of' => count($node->rollup->children),
                'missing' => array_map(fn (ChildReport $child) => $child->unit->id, $node->rollup->missingChildren()),
            ],
            'notes' => $node->report?->notes,
            'savedAt' => $node->report?->updated_at?->toIso8601String(),
            'sentAt' => $node->report?->sent_at?->toIso8601String(),
            'sentBy' => $node->report?->sent_by === null ? null : (
                // "Hale.S for Mercer.J" when an Admin acting as the sender sent it (#379).
                $node->report->sent_by_admin === null ? '' : $senders->get($node->report->sent_by_admin).' for '
            ).$senders->get($node->report->sent_by).(
                // "Hale.S acting as Section Leader 2/1/A/1-7" when an Admin holding the billet sent it.
                $node->report->sent_as_unit === null ? '' : ' acting as '.$this->units->find($node->report->sent_as_unit)?->billet()
            ),
            'reportUrl' => '/reports/'.$node->unit->id.'/'.$week->toDateString(),
            'children' => array_map(fn (ChainNode $child) => $this->present($child, $week, $senders), $node->children),
        ];
    }

    /** @return array<string, mixed> */
    private static function kpis(UnitKpis $kpis): array
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

    /** @return array<string, int|null>|null */
    private static function delta(?KpiDelta $delta): ?array
    {
        return $delta === null ? null : [
            'strength' => $delta->strength,
            'rcBp' => $delta->rc30dBp,
            'spBp' => $delta->sp30dBp,
            'atRisk' => $delta->atRisk,
            'onLoa' => $delta->onLoa,
            'makeups' => $delta->makeups,
        ];
    }
}
