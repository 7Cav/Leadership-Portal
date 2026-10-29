<?php

use App\Commands\WeeklyReports\InvalidReportContentException;
use App\Commands\WeeklyReports\SaveReportDraft;
use App\Commands\WeeklyReports\SaveReportDraftHandler;
use App\Commands\WeeklyReports\SendWeeklyReportForbiddenException;
use App\Kpi\ReportSnapshot;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;

// 5.5 / AD-16, AD-8: a draft save is audited, scope-checked, and never touches a sent report.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '710/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '710/711/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->trooper = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    // Writes are refused before the week floor, which counts back from now: pin now to this week.
    CarbonImmutable::setTestNow($this->moment);
});

function saveDraft(User $actor, int $unitId, DateTimeImmutable $moment, ?string $notes = 'Notes', ?string $subject = null, array $unaccounted = []): WeeklyReport
{
    return app(SaveReportDraftHandler::class)->handle($actor, new SaveReportDraft($unitId, $moment, $notes, $subject, $unaccounted));
}

it('saves the draft with the live snapshot as its last saved copy, and audits it without a reason', function () {
    $report = saveDraft($this->leader, $this->section->id, $this->moment, '  Notes  ', '', [['trooper_id' => $this->trooper->id, 'reason' => 'technical']]);

    expect($report->notes)->toBe('Notes')
        ->and($report->training_subject)->toBeNull()
        ->and($report->unaccounted)->toBe([['trooper_id' => $this->trooper->id, 'reason' => 'technical']])
        ->and(ReportSnapshot::fromArray($report->kpis)->kpis->strength)->toBe(1)
        ->and(AuditLogEntry::query()->sole())
        ->action->toBe(SaveReportDraftHandler::ACTION)
        ->reason->toBeNull();
});

it('updates the one draft row on a second save', function () {
    saveDraft($this->leader, $this->section->id, $this->moment, 'First');
    saveDraft($this->leader, $this->section->id, $this->moment, 'Second');

    expect(WeeklyReport::query()->sole()->notes)->toBe('Second');
});

it('refuses a unit outside the actor\'s scope', function () {
    saveDraft(User::factory()->serving()->create(), $this->section->id, $this->moment);
})->throws(SendWeeklyReportForbiddenException::class);

it('refuses unaccounted troopers on a roll-up, and a trooper listed twice', function (Closure $call) {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();

    expect(fn () => $call($this, $platoonLeader))->toThrow(InvalidReportContentException::class);
    expect(WeeklyReport::query()->exists())->toBeFalse();
})->with([
    'roll-up' => [fn ($test, $pl) => saveDraft($pl, $test->platoon->id, $test->moment, null, null, [['trooper_id' => $test->trooper->id, 'reason' => 'sick']])],
    'twice' => [fn ($test, $pl) => saveDraft($test->leader, $test->section->id, $test->moment, null, null, [
        ['trooper_id' => $test->trooper->id, 'reason' => 'sick'], ['trooper_id' => $test->trooper->id, 'reason' => 'technical'],
    ])],
]);

it('refuses to touch a sent report, leaving it and the audit log as they were', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart, [], 'Frozen', $this->leader->id, 'd', null);

    expect(fn () => saveDraft($this->leader, $this->section->id, $this->moment, 'Changed'))
        ->toThrow(WeeklyReportAlreadySentException::class);
    expect(WeeklyReport::query()->sole()->notes)->toBe('Frozen')
        ->and(AuditLogEntry::query()->exists())->toBeFalse();
});

it('takes "No reason given" for a trooper who missed and said nothing', function () {
    $report = saveDraft($this->leader, $this->section->id, $this->moment, 'Notes', null, [['trooper_id' => $this->trooper->id, 'reason' => 'no_reason']]);

    expect($report->unaccounted)->toBe([['trooper_id' => $this->trooper->id, 'reason' => 'no_reason']]);
});
