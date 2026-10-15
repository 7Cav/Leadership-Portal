<?php

use App\Commands\WeeklyReports\SaveReportDraft;
use App\Commands\WeeklyReports\SaveReportDraftHandler;
use App\Commands\WeeklyReports\SectionTrainingSubject;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Commands\WeeklyReports\TrainingSubjectEnforcedException;
use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;

// A section's training subject against its platoon's topic for the week. Precedence: an enforced
// platoon topic, then the section's own entry, then a platoon topic that is not enforced. An
// enforced topic holds on the server, whatever the page sends.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '720/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '720/721/']);
    $this->leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);

    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
});

function platoonTopic(Unit $platoon, CarbonImmutable $weekStart, string $subject, bool $enforced): PlatoonTrainingTopic
{
    return app(PlatoonTrainingTopicRepository::class)->set($platoon->id, $weekStart, $subject, $enforced, User::factory()->create()->id);
}

function draftSubject(User $actor, Unit $section, DateTimeImmutable $moment, ?string $subject): WeeklyReport
{
    return app(SaveReportDraftHandler::class)->handle($actor, new SaveReportDraft($section->id, $moment, 'Notes', $subject));
}

function sendSubject(User $actor, Unit $section, DateTimeImmutable $moment, ?string $subject): WeeklyReport
{
    return app(SendWeeklyReportHandler::class)->handle($actor, new SendWeeklyReport($section->id, $moment, 'Weekly report', 'Notes', $subject))->report;
}

it('ranks an enforced topic over the section\'s own entry, and the own entry over a default', function (?string $topic, bool $enforced, ?string $own, ?string $expected) {
    $model = $topic === null ? null : new PlatoonTrainingTopic(['subject' => $topic, 'enforced' => $enforced]);

    expect(SectionTrainingSubject::effective($model, $own))->toBe($expected);
})->with([
    'enforced beats own' => ['Room clearing', true, 'Radio', 'Room clearing'],
    'own beats default' => ['Room clearing', false, 'Radio', 'Radio'],
    'default when no own entry' => ['Room clearing', false, null, 'Room clearing'],
    'own with no topic' => [null, false, 'Radio', 'Radio'],
    'nothing' => [null, false, null, null],
]);

it('freezes the enforced topic on send, whatever subject the section left blank', function () {
    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', true);

    expect(sendSubject($this->leader, $this->section, $this->moment, null)->training_subject)->toBe('Room clearing');
});

it('refuses a section send or draft that changes an enforced topic, writing nothing', function (string $via) {
    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', true);
    $write = $via === 'send' ? sendSubject(...) : draftSubject(...);

    expect(fn () => $write($this->leader, $this->section, $this->moment, 'Something else'))
        ->toThrow(TrainingSubjectEnforcedException::class);
    expect(WeeklyReport::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->exists())->toBeFalse();
})->with(['send', 'draft']);

it('keeps the section\'s own entry under an enforced topic, so it is back if the lock is lifted', function () {
    draftSubject($this->leader, $this->section, $this->moment, 'Radio procedure');
    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', true);

    expect(draftSubject($this->leader, $this->section, $this->moment, 'Room clearing')->training_subject)->toBe('Radio procedure');

    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', false);
    expect(sendSubject($this->leader, $this->section, $this->moment, 'Radio procedure')->training_subject)->toBe('Radio procedure');
});

it('lets the section change a topic that is not enforced, and freezes what it confirmed', function () {
    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', false);

    expect(draftSubject($this->leader, $this->section, $this->moment, 'Radio procedure')->training_subject)->toBe('Radio procedure')
        ->and(sendSubject($this->leader, $this->section, $this->moment, 'Radio procedure')->training_subject)->toBe('Radio procedure');
});

it('does not store the platoon\'s default as the section\'s own entry, so a later change to it carries through', function () {
    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', false);
    expect(draftSubject($this->leader, $this->section, $this->moment, 'Room clearing')->training_subject)->toBeNull();

    platoonTopic($this->platoon, $this->weekStart, 'Radio procedure', false);
    expect(sendSubject($this->leader, $this->section, $this->moment, 'Radio procedure')->training_subject)->toBe('Radio procedure');
});

it('leaves a report sent before the topic was set as it was frozen', function () {
    sendSubject($this->leader, $this->section, $this->moment, 'Radio procedure');
    platoonTopic($this->platoon, $this->weekStart, 'Room clearing', true);

    expect(app(WeeklyReportRepository::class)->find($this->section->id, $this->weekStart)->training_subject)->toBe('Radio procedure');
});

it('only reads the topic of the section\'s own platoon and week', function () {
    $otherPlatoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '730/']);
    platoonTopic($otherPlatoon, $this->weekStart, 'Room clearing', true);
    platoonTopic($this->platoon, $this->weekStart->subWeek(), 'Room clearing', true);

    expect(sendSubject($this->leader, $this->section, $this->moment, 'Radio procedure')->training_subject)->toBe('Radio procedure');
});
