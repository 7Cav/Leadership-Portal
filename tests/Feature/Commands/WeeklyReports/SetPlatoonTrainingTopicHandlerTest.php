<?php

use App\Commands\WeeklyReports\InvalidPlatoonTrainingTopicException;
use App\Commands\WeeklyReports\PlatoonTrainingTopicForbiddenException;
use App\Commands\WeeklyReports\SetPlatoonTrainingTopic;
use App\Commands\WeeklyReports\SetPlatoonTrainingTopicHandler;
use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;

// A platoon leader sets the week's training topic for their sections (enforced or a default), as an
// audited write that only the platoon's chain of command or an Admin may make.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->otherPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);

    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->leader = topicCommander($this->platoon->id, 801);
});

function topicCommander(int $unitId, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unitId, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

function setTopic(User $actor, int $unitId, DateTimeImmutable $moment, ?string $subject, bool $enforced = false): ?PlatoonTrainingTopic
{
    return app(SetPlatoonTrainingTopicHandler::class)->handle($actor, new SetPlatoonTrainingTopic($unitId, $moment, $subject, $enforced));
}

it('sets the topic for the week the moment falls in, and audits it without a reason', function () {
    $topic = setTopic($this->leader, $this->platoon->id, $this->moment, '  Room clearing  ', true);

    expect($topic->subject)->toBe('Room clearing')
        ->and($topic->enforced)->toBeTrue()
        ->and($topic->set_by)->toBe($this->leader->id)
        ->and($topic->week_start->equalTo($this->weekStart))->toBeTrue()
        ->and(AuditLogEntry::query()->sole())
        ->action->toBe(SetPlatoonTrainingTopicHandler::SET)
        ->target_type->toBe('PlatoonTrainingTopic')
        ->target_id->toBe((string) $topic->id)
        ->actor_id->toBe($this->leader->id)
        ->reason->toBeNull();
});

it('replaces the week\'s topic in place on a second set', function () {
    setTopic($this->leader, $this->platoon->id, $this->moment, 'Room clearing', true);
    setTopic($this->leader, $this->platoon->id, $this->moment, 'Radio procedure', false);

    expect(PlatoonTrainingTopic::query()->sole())
        ->subject->toBe('Radio procedure')
        ->enforced->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', SetPlatoonTrainingTopicHandler::SET)->count())->toBe(2);
});

it('clears the topic with a blank subject, audited, and treats clearing nothing as a no-op', function () {
    expect(setTopic($this->leader, $this->platoon->id, $this->moment, ''))->toBeNull()
        ->and(AuditLogEntry::query()->exists())->toBeFalse();

    setTopic($this->leader, $this->platoon->id, $this->moment, 'Room clearing');
    expect(setTopic($this->leader, $this->platoon->id, $this->moment, null))->toBeNull()
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->latest('id')->first()->action)->toBe(SetPlatoonTrainingTopicHandler::CLEARED);
});

it('refuses anyone who does not command the platoon, writing nothing', function (Closure $actor) {
    expect(fn () => setTopic($actor($this), $this->platoon->id, $this->moment, 'Room clearing'))
        ->toThrow(PlatoonTrainingTopicForbiddenException::class);
    expect(PlatoonTrainingTopic::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->exists())->toBeFalse();
})->with([
    'the section leader' => [fn ($test) => topicCommander($test->section->id, 802)],
    'another platoon\'s leader' => [fn ($test) => topicCommander($test->otherPlatoon->id, 803)],
    'a staff grant holder on the platoon (read-only)' => [function ($test) {
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $test->platoon->id]);

        return $staff;
    }],
    'a user with no scope' => [fn () => User::factory()->serving()->create()],
]);

it('lets an Admin and the company commander above the platoon set it', function (Closure $actor) {
    expect(setTopic($actor($this), $this->platoon->id, $this->moment, 'Room clearing'))->not->toBeNull();
})->with([
    'Admin' => [fn () => User::factory()->admin()->create()],
    'company commander' => [fn ($test) => topicCommander($test->company->id, 804)],
]);

it('refuses a unit that is not a platoon', function () {
    $commander = topicCommander($this->company->id, 805);

    setTopic($commander, $this->company->id, $this->moment, 'Room clearing');
})->throws(InvalidPlatoonTrainingTopicException::class);

it('refuses once the platoon\'s own report for the week is sent', function () {
    app(WeeklyReportRepository::class)->send($this->platoon->id, $this->weekStart, [], null, $this->leader->id, 'd', null);

    expect(fn () => setTopic($this->leader, $this->platoon->id, $this->moment, 'Room clearing'))
        ->toThrow(InvalidPlatoonTrainingTopicException::class);
    expect(PlatoonTrainingTopic::query()->exists())->toBeFalse();
});
