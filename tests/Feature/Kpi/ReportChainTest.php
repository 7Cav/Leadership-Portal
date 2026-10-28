<?php

use App\Kpi\ChainNode;
use App\Kpi\ChildReportStatus;
use App\Kpi\KpiDelta;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\ReportChain;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

// 5.8: the navigator's read model. Every node is either its frozen snapshot (sent), or live and
// labelled so: a leaf from KpiService, a non-leaf from its children's SENT snapshots only (AD-13).
// Fixture tree:
//   1-7 ─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A   (sent)
//                │       └─ 2/1/A   (draft, live attendance)
//                └─ 2/A ─── 1/2/A   (not started)
// `week_start` is 2026-10-03 (a Saturday).

function chainWeek(int $weeksBack = 0): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')->subWeeks($weeksBack);
}

function chainKpis(int $strength, array $rc, array $sp, int $atRisk = 0): UnitKpis
{
    return new UnitKpis($strength, new Ratio(...$rc), new Ratio(...$sp), $atRisk, 0, 0);
}

/** @return array<int, ChainNode> every node in the tree, keyed by unit id */
function chainIndex(ChainNode $node): array
{
    $all = [$node->unit->id => $node];
    foreach ($node->children as $child) {
        $all += chainIndex($child);
    }

    return $all;
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->p1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->p2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->s11 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->p1->id);
    $this->s21 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->p1->id);
    $this->s12 = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $this->p2->id);
    // Someone in every section, so none is an empty unit left out of the chain (#442).
    manUnits($this->s11, $this->s21, $this->s12);

    $this->reports = app(WeeklyReportRepository::class);
    $this->sender = User::factory()->create();
    $this->send = fn (Unit $unit, UnitKpis $kpis, ?KpiDelta $delta = null, int $weeksBack = 0) => $this->reports
        ->send($unit->id, chainWeek($weeksBack), (new ReportSnapshot($kpis, $delta))->toArray(), 'sent notes', $this->sender->id, 'digest', null);
    $this->chain = app(ReportChain::class);
});

it('builds the tree from the root down, children in creation order', function () {
    $root = $this->chain->build($this->company, chainWeek());

    expect($root->unit->id)->toBe($this->company->id)
        ->and(array_map(fn (ChainNode $n) => $n->unit->id, $root->children))->toBe([$this->p1->id, $this->p2->id])
        ->and(array_map(fn (ChainNode $n) => $n->unit->id, $root->children[0]->children))->toBe([$this->s11->id, $this->s21->id])
        ->and($root->children[0]->children[0]->children)->toBe([])
        ->and($root->children[0]->children[0]->rollup)->toBeNull();
});

it('shows a sent unit\'s frozen snapshot, delta included, never a live recompute', function () {
    $frozen = chainKpis(6, [20, 24], [10, 12]);
    $delta = new KpiDelta(1, 200, -150, 0, 0, 0);
    ($this->send)($this->s11, $frozen, $delta);
    // Attendance recorded after the send must not move the frozen numbers.
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $this->s11->id])->save();

    $node = chainIndex($this->chain->build($this->company, chainWeek()))[$this->s11->id];

    expect($node->status)->toBe(ChildReportStatus::Sent)
        ->and($node->live)->toBeFalse()
        ->and($node->kpis)->toEqual($frozen)
        ->and($node->delta)->toEqual($delta)
        ->and($node->report->notes)->toBe('sent notes');
});

it('shows an unsent section\'s live KPIs from the shared service, with the delta against last week\'s sent snapshot', function () {
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $this->s21->id])->save();
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->s21->id, chainWeek());
    app(AttendanceRepository::class)->markPractice($practice, $trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
    app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, RollcallSignature::WillAttend);
    $this->reports->saveDraft($this->s21->id, chainWeek(), ['draft' => true], 'draft notes');
    ($this->send)($this->s21, chainKpis(2, [1, 8], [1, 2]), weeksBack: 1);

    $node = chainIndex($this->chain->build($this->company, chainWeek()))[$this->s21->id];
    $live = app(KpiService::class)->forUnit($this->s21, chainWeek());

    expect($node->status)->toBe(ChildReportStatus::Draft)
        ->and($node->live)->toBeTrue()
        ->and($node->kpis)->toEqual($live)
        ->and($node->delta)->toEqual(KpiDelta::between($live, chainKpis(2, [1, 8], [1, 2])))
        ->and($node->report->notes)->toBe('draft notes');
});

it('gives no delta when last week was not sent', function () {
    $this->reports->saveDraft($this->s12->id, chainWeek(1), [], null);

    $node = chainIndex($this->chain->build($this->company, chainWeek()))[$this->s12->id];

    expect($node->status)->toBe(ChildReportStatus::NotStarted)
        ->and($node->report)->toBeNull()
        ->and($node->delta)->toBeNull()
        ->and($node->kpis)->toEqual(app(KpiService::class)->forUnit($this->s12, chainWeek()));
});

// #442: a line unit with nobody in it or under it has nobody to report on.
it('leaves out a unit with no troopers in it or under it, so it holds nobody up', function () {
    $empty = app(UnitRepository::class)->create(UnitType::Platoon, '3rd Platoon', '3/A/1-7', $this->company->id);
    app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/3/A/1-7', $empty->id);
    ($this->send)($this->p1, chainKpis(2, [2, 8], [1, 2]));
    ($this->send)($this->p2, chainKpis(1, [1, 4], [1, 1]));

    $company = $this->chain->build($this->company, chainWeek());

    expect(array_map(fn (ChainNode $n) => $n->unit->id, $company->children))->toBe([$this->p1->id, $this->p2->id])
        ->and(array_keys(chainIndex($company)))->not->toContain($empty->id)
        ->and($company->isHolding())->toBeFalse()
        ->and($company->kpis)->toEqual(chainKpis(3, [3, 12], [2, 3]));
});

it('still draws the root it was asked for when nobody is in it', function () {
    $empty = app(UnitRepository::class)->create(UnitType::Platoon, '3rd Platoon', '3/A/1-7', $this->company->id);
    app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/3/A/1-7', $empty->id);

    $root = $this->chain->build($empty, chainWeek());

    expect($root->unit->id)->toBe($empty->id)->and($root->children)->toBe([]);
});

it('rolls an unsent non-leaf up from its children\'s sent snapshots only, and marks it holding', function () {
    ($this->send)($this->s11, chainKpis(6, [20, 24], [10, 12], atRisk: 1));
    $this->reports->saveDraft($this->s21->id, chainWeek(), [], null);

    $index = chainIndex($this->chain->build($this->company, chainWeek()));
    $platoon = $index[$this->p1->id];

    expect($platoon->kpis)->toEqual(chainKpis(6, [20, 24], [10, 12], atRisk: 1))
        ->and($platoon->live)->toBeTrue()
        ->and($platoon->isHolding())->toBeTrue()
        ->and(array_map(fn ($c) => $c->unit->id, $platoon->rollup->missingChildren()))->toBe([$this->s21->id])
        // The company rolls up its platoons' sent snapshots — none sent, so nothing yet.
        ->and($index[$this->company->id]->kpis)->toEqual(UnitKpis::none())
        ->and($index[$this->company->id]->isHolding())->toBeTrue();
});

it('is not holding once every child has sent, and a sent branch has no open reports', function () {
    ($this->send)($this->s11, chainKpis(6, [20, 24], [10, 12]));
    ($this->send)($this->s21, chainKpis(4, [8, 16], [3, 4]));

    $index = chainIndex($this->chain->build($this->company, chainWeek()));

    expect($index[$this->p1->id]->isHolding())->toBeFalse()
        ->and($index[$this->p1->id]->open)->toBeTrue()   // its own report is still unsent
        ->and($index[$this->s11->id]->open)->toBeFalse()
        ->and($index[$this->p1->id]->kpis)->toEqual(chainKpis(10, [28, 40], [13, 16]));
});

it('flags a sent unit whose descendant is still open', function () {
    ($this->send)($this->p1, chainKpis(6, [20, 24], [10, 12]));

    $node = chainIndex($this->chain->build($this->company, chainWeek()))[$this->p1->id];

    expect($node->status)->toBe(ChildReportStatus::Sent)
        ->and($node->isHolding())->toBeFalse()
        ->and($node->open)->toBeTrue();
});

// Seven reads of its own (two of them which units have troopers, #442), plus the LOA coverage of the window (4.8):
// one more, however many units.
it('reads a whole battalion in eight queries, however many units it holds', function () {
    $units = app(UnitRepository::class);
    $count = function (Unit $root) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->chain->build($root, chainWeek());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count($this->battalion);

    foreach (['B', 'C'] as $letter) {
        $company = $units->create(UnitType::Company, "{$letter} Company", "{$letter}/1-7", $this->battalion->id);
        foreach ([1, 2] as $p) {
            $platoon = $units->create(UnitType::Platoon, "Platoon {$p}", "{$p}/{$letter}/1-7", $company->id);
            foreach ([1, 2, 3, 4] as $s) {
                $section = $units->create(UnitType::Section, "Section {$s}", "{$s}/{$p}/{$letter}/1-7", $platoon->id);
                $trooper = Trooper::factory()->create();
                $trooper->forceFill(['primary_section_id' => $section->id])->save();
                ($this->send)($section, chainKpis(1, [1, 4], [0, 1]));
                ($this->send)($section, chainKpis(1, [1, 4], [0, 1]), weeksBack: 1);
            }
        }
    }

    expect($small)->toBe(8)->and($count($this->battalion))->toBe(8);
});

it('shows a unit with an unreadable sent snapshot as unknown, never a plausible zero, and still builds the rest of the chain', function () {
    Exceptions::fake();
    ($this->send)($this->s11, chainKpis(6, [20, 24], [10, 12]));
    ($this->send)($this->s12, chainKpis(3, [6, 12], [3, 6]));
    WeeklyReport::query()->where('unit_id', $this->s11->id)->update(['kpis' => json_encode(['strength' => 6])]);

    $nodes = chainIndex($this->chain->build($this->company, chainWeek()));

    // The corrupt unit itself: sent, but its numbers are unknown.
    expect($nodes[$this->s11->id]->status)->toBe(ChildReportStatus::Sent)
        ->and($nodes[$this->s11->id]->kpis)->toBeNull()
        ->and($nodes[$this->s11->id]->delta)->toBeNull()
        // Its unsent platoon has nothing true to sum: no roll-up, no KPIs, not reported as holding.
        ->and($nodes[$this->p1->id]->rollup)->toBeNull()
        ->and($nodes[$this->p1->id]->kpis)->toBeNull()
        ->and($nodes[$this->p1->id]->isHolding())->toBeFalse()
        // The rest of the chain reads as before.
        ->and($nodes[$this->s21->id]->kpis)->not->toBeNull()
        ->and($nodes[$this->p2->id]->kpis)->toEqual(chainKpis(3, [6, 12], [3, 6]))
        ->and($nodes[$this->company->id]->rollup)->not->toBeNull();

    // Reported once, though the platoon's roll-up and the section's own node both needed it.
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (ReportKpisUnavailableException $e) => $e->unreadable?->unit_id === $this->s11->id);
});

it('gives an unsent unit no delta when its prior week\'s sent snapshot is unreadable, and keeps its numbers', function () {
    Exceptions::fake();
    ($this->send)($this->s12, chainKpis(3, [6, 12], [3, 6]), weeksBack: 1);
    WeeklyReport::query()->where('unit_id', $this->s12->id)->update(['kpis' => json_encode(['corrupt' => true])]);

    $node = chainIndex($this->chain->build($this->company, chainWeek()))[$this->s12->id];

    expect($node->kpis)->not->toBeNull()->and($node->delta)->toBeNull();
    Exceptions::assertReportedCount(1);
});

it('gives an unsent non-leaf the delta of its roll-up against its own last sent report', function () {
    $prior = chainKpis(4, [10, 16], [2, 4]);
    ($this->send)($this->p1, $prior, weeksBack: 1);
    ($this->send)($this->s11, chainKpis(6, [20, 24], [10, 12]));

    $platoon = chainIndex($this->chain->build($this->company, chainWeek()))[$this->p1->id];

    expect($platoon->live)->toBeTrue()
        ->and($platoon->delta)->toEqual(KpiDelta::between($platoon->rollup->kpis, $prior))
        ->and($platoon->delta->strength)->toBe(2);
});
