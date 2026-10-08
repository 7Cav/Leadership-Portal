<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

// 5.2 / AD-12, AD-11: one report per (unit, week_start), week_start always a Saturday 00:00 UTC,
// and a sent snapshot is frozen — never overwritten by a later draft save or a second send.

/**
 * The six canonical KPIs (AD-21) with numerator/denominator and delta-vs-last-week — the shape
 * 5.1/5.4 hand this layer. The repository stores it as given; this fixture only proves the JSON
 * column round-trips it, including fractional rates and null deltas.
 */
function sixKpiSnapshot(): array
{
    return [
        'strength' => ['value' => 12, 'delta' => 1],
        'rc_30d' => ['numerator' => 40, 'denominator' => 48, 'rate' => 0.8333, 'delta' => -0.05],
        'sp_30d' => ['numerator' => 36, 'denominator' => 48, 'rate' => 0.75, 'delta' => 0.0],
        'at_risk' => ['value' => 2, 'delta' => null],
        'on_loa' => ['value' => 0, 'delta' => 0],
        'makeups' => ['value' => 3, 'delta' => 2],
    ];
}

beforeEach(function () {
    $this->repo = app(WeeklyReportRepository::class);
    $this->unit = Unit::factory()->create(['type' => UnitType::Platoon]);
    $this->sender = User::factory()->create();
    $this->saturday = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
});

it('returns null for a unit-week with no report', function () {
    expect($this->repo->find($this->unit->id, $this->saturday))->toBeNull();
});

it('saves a draft with its kpis and notes', function () {
    $report = $this->repo->saveDraft($this->unit->id, $this->saturday, sixKpiSnapshot(), 'Quiet week.');

    expect($report->exists)->toBeTrue()
        ->and($report->status)->toBe(WeeklyReportStatus::Draft)
        ->and($report->unit_id)->toBe($this->unit->id)
        ->and($report->week_start->equalTo($this->saturday))->toBeTrue()
        ->and($report->kpis)->toBe(sixKpiSnapshot())
        ->and($report->notes)->toBe('Quiet week.')
        ->and($report->sent_at)->toBeNull()
        ->and($report->sent_by)->toBeNull()
        ->and($report->content_digest)->toBeNull()
        ->and($report->template_version_id)->toBeNull();
});

it('round-trips the six-KPI snapshot through the database unchanged', function () {
    $this->repo->saveDraft($this->unit->id, $this->saturday, sixKpiSnapshot(), null);

    expect($this->repo->find($this->unit->id, $this->saturday)->kpis)->toBe(sixKpiSnapshot());
});

it('updates the one draft row on a repeat save rather than adding a second', function () {
    $first = $this->repo->saveDraft($this->unit->id, $this->saturday, ['strength' => ['value' => 1]], 'v1');
    $second = $this->repo->saveDraft($this->unit->id, $this->saturday, sixKpiSnapshot(), 'v2');

    expect($second->id)->toBe($first->id)
        ->and(WeeklyReport::query()->count())->toBe(1)
        ->and($this->repo->find($this->unit->id, $this->saturday))
        ->kpis->toBe(sixKpiSnapshot())
        ->notes->toBe('v2');
});

it('sends a unit-week with no prior draft, recording the frozen snapshot and send metadata', function () {
    Date::setTestNow('2026-10-09 21:15:00');

    $report = $this->repo->send(
        $this->unit->id, $this->saturday, sixKpiSnapshot(), 'All good.', $this->sender->id, str_repeat('a', 64), 7,
    );

    $stored = $this->repo->find($this->unit->id, $this->saturday);

    expect($report->id)->toBe($stored->id)
        ->and($stored->status)->toBe(WeeklyReportStatus::Sent)
        ->and($stored->kpis)->toBe(sixKpiSnapshot())
        ->and($stored->notes)->toBe('All good.')
        ->and($stored->sent_by)->toBe($this->sender->id)
        ->and($stored->sent_at->equalTo(CarbonImmutable::parse('2026-10-09 21:15:00', 'UTC')))->toBeTrue()
        ->and($stored->content_digest)->toBe(str_repeat('a', 64))
        ->and($stored->template_version_id)->toBe(7);
});

it('sends an existing draft in place, replacing its kpis and notes with the sent snapshot', function () {
    $draft = $this->repo->saveDraft($this->unit->id, $this->saturday, ['strength' => ['value' => 1]], 'draft');

    $sent = $this->repo->send(
        $this->unit->id, $this->saturday, sixKpiSnapshot(), 'final', $this->sender->id, 'digest', null,
    );

    expect($sent->id)->toBe($draft->id)
        ->and(WeeklyReport::query()->count())->toBe(1)
        ->and($sent->status)->toBe(WeeklyReportStatus::Sent)
        ->and($sent->kpis)->toBe(sixKpiSnapshot())
        ->and($sent->notes)->toBe('final')
        ->and($sent->template_version_id)->toBeNull();
});

it('refuses a draft save over a sent report and leaves the snapshot untouched', function () {
    $this->repo->send($this->unit->id, $this->saturday, sixKpiSnapshot(), 'final', $this->sender->id, 'digest', null);

    expect(fn () => $this->repo->saveDraft($this->unit->id, $this->saturday, ['strength' => ['value' => 0]], 'edit'))
        ->toThrow(WeeklyReportAlreadySentException::class);

    expect($this->repo->find($this->unit->id, $this->saturday))
        ->status->toBe(WeeklyReportStatus::Sent)
        ->kpis->toBe(sixKpiSnapshot())
        ->notes->toBe('final');
});

it('refuses a second send and leaves the first snapshot and metadata untouched', function () {
    Date::setTestNow('2026-10-09 21:15:00');
    $this->repo->send($this->unit->id, $this->saturday, sixKpiSnapshot(), 'first', $this->sender->id, 'digest-1', 3);

    Date::setTestNow('2026-10-10 08:00:00');
    $other = User::factory()->create();

    expect(fn () => $this->repo->send($this->unit->id, $this->saturday, [], 'second', $other->id, 'digest-2', 4))
        ->toThrow(WeeklyReportAlreadySentException::class);

    $stored = $this->repo->find($this->unit->id, $this->saturday);

    expect($stored->notes)->toBe('first')
        ->and($stored->kpis)->toBe(sixKpiSnapshot())
        ->and($stored->sent_by)->toBe($this->sender->id)
        ->and($stored->content_digest)->toBe('digest-1')
        ->and($stored->template_version_id)->toBe(3)
        ->and($stored->sent_at->equalTo(CarbonImmutable::parse('2026-10-09 21:15:00', 'UTC')))->toBeTrue();
});

it('names the unit and week in the already-sent refusal', function () {
    $this->repo->send($this->unit->id, $this->saturday, [], null, $this->sender->id, 'digest', null);

    expect(fn () => $this->repo->send($this->unit->id, $this->saturday, [], null, $this->sender->id, 'digest', null))
        ->toThrow(WeeklyReportAlreadySentException::class, "unit {$this->unit->id}")
        ->and(fn () => $this->repo->send($this->unit->id, $this->saturday, [], null, $this->sender->id, 'digest', null))
        ->toThrow(WeeklyReportAlreadySentException::class, '2026-10-03');
});

it('keeps separate reports per unit and per week', function () {
    $otherUnit = Unit::factory()->create();

    $this->repo->send($this->unit->id, $this->saturday, sixKpiSnapshot(), null, $this->sender->id, 'digest', null);
    $this->repo->saveDraft($this->unit->id, $this->saturday->addWeek(), sixKpiSnapshot(), null);
    $this->repo->saveDraft($otherUnit->id, $this->saturday, sixKpiSnapshot(), null);

    expect(WeeklyReport::query()->count())->toBe(3)
        ->and($this->repo->find($this->unit->id, $this->saturday->addWeek())->status)->toBe(WeeklyReportStatus::Draft);
});

it('accepts a report for a unit of any echelon', function (UnitType $type) {
    $unit = Unit::factory()->create(['type' => $type]);

    expect($this->repo->saveDraft($unit->id, $this->saturday, sixKpiSnapshot(), null)->exists)->toBeTrue();
})->with(UnitType::cases());

it('refuses a report for a unit id that does not exist', function () {
    expect(fn () => $this->repo->saveDraft(999999, $this->saturday, sixKpiSnapshot(), null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->repo->send(999999, $this->saturday, sixKpiSnapshot(), null, $this->sender->id, 'd', null))
        ->toThrow(InvalidArgumentException::class)
        ->and(WeeklyReport::query()->count())->toBe(0);
});

it('accepts a Saturday 00:00 UTC given in another timezone and stores it as UTC', function () {
    // Friday 20:00 in New York (EDT, UTC-4) is Saturday 00:00 UTC.
    $sameInstant = CarbonImmutable::parse('2026-10-02 20:00:00', 'America/New_York');

    $report = $this->repo->saveDraft($this->unit->id, $sameInstant, sixKpiSnapshot(), null);

    expect($report->getRawOriginal('week_start'))->toBe('2026-10-03 00:00:00')
        ->and($this->repo->find($this->unit->id, $this->saturday)?->id)->toBe($report->id)
        ->and($this->repo->find($this->unit->id, $sameInstant)?->id)->toBe($report->id);
});

it('rejects a week_start that is not a Saturday 00:00 UTC on every read and write', function (string $time, string $zone) {
    $weekStart = CarbonImmutable::parse($time, $zone);

    expect(fn () => $this->repo->find($this->unit->id, $weekStart))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->repo->saveDraft($this->unit->id, $weekStart, sixKpiSnapshot(), null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->repo->send($this->unit->id, $weekStart, sixKpiSnapshot(), null, $this->sender->id, 'd', null))
        ->toThrow(InvalidArgumentException::class)
        ->and(WeeklyReport::query()->count())->toBe(0);
})->with([
    'a Friday' => ['2026-10-02 00:00:00', 'UTC'],
    'a Sunday' => ['2026-10-04 00:00:00', 'UTC'],
    'Saturday, one second past midnight' => ['2026-10-03 00:00:01', 'UTC'],
    'Saturday midday' => ['2026-10-03 12:00:00', 'UTC'],
    'Saturday 00:00 local but not UTC' => ['2026-10-03 00:00:00', 'America/New_York'],
]);
