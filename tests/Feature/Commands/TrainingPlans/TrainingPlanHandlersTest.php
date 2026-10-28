<?php

use App\Commands\TrainingPlans\ApproveTrainingPlan;
use App\Commands\TrainingPlans\ApproveTrainingPlanHandler;
use App\Commands\TrainingPlans\InvalidTrainingPlanException;
use App\Commands\TrainingPlans\ReturnTrainingPlan;
use App\Commands\TrainingPlans\ReturnTrainingPlanHandler;
use App\Commands\TrainingPlans\SaveTrainingPlan;
use App\Commands\TrainingPlans\SaveTrainingPlanHandler;
use App\Commands\TrainingPlans\TrainingPlanForbiddenException;
use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanStatus;
use App\Models\Trooper;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\ReasonRequiredException;
use Carbon\CarbonImmutable;

// #380: a platoon's chain of command writes a monthly training plan, the company's approves it or sends it back, and
// an approved plan becomes the platoon's weekly training topic. Every write is audited (AD-16).

beforeEach(function () {
    // Wednesday of the Cav week that opened Saturday 2026-10-24. November's weeks open on the 7th, 14th, 21st and 28th.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));

    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->otherPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);

    $this->leader = tpCommander($this->platoon->id, 701);
    $this->commander = tpCommander($this->company->id, 702);
    $this->battalionCommander = tpCommander($this->battalion->id, 703);
    $this->otherLeader = tpCommander($this->otherPlatoon->id, 704);
    $this->sectionLeader = tpCommander($this->section->id, 705);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function tpCommander(int $unitId, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unitId, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

function tpSave(User $actor, int $unitId, array $subjects, bool $enforced = false, string $month = '2026-11'): TrainingPlan
{
    return app(SaveTrainingPlanHandler::class)->handle($actor, new SaveTrainingPlan($unitId, $month, $subjects, $enforced));
}

function tpApprove(User $actor, int $unitId, int $revision = 1, string $month = '2026-11'): TrainingPlan
{
    return app(ApproveTrainingPlanHandler::class)->handle($actor, new ApproveTrainingPlan($unitId, $month, $revision));
}

function tpReturn(User $actor, int $unitId, string $comment, int $revision = 1, string $month = '2026-11'): TrainingPlan
{
    return app(ReturnTrainingPlanHandler::class)->handle($actor, new ReturnTrainingPlan($unitId, $month, $revision, $comment));
}

function tpTopics(int $unitId): array
{
    return PlatoonTrainingTopic::query()->where('unit_id', $unitId)->orderBy('week_start')->get()
        ->map(fn (PlatoonTrainingTopic $t) => [$t->week_start->toDateString(), $t->subject, $t->enforced])->all();
}

it('lets the platoon leader write a plan, pending review, audited without a reason', function () {
    $plan = tpSave($this->leader, $this->platoon->id, ['2026-11-07' => '  Room clearing ', '2026-11-14' => '', '2026-11-21' => 'Radio procedure'], true);

    expect($plan->status)->toBe(TrainingPlanStatus::Pending)
        ->and($plan->enforced)->toBeTrue()
        ->and($plan->weeks->pluck('subject')->all())->toBe(['Room clearing', 'Radio procedure'])
        ->and(AuditLogEntry::query()->sole())
        ->action->toBe(SaveTrainingPlanHandler::SAVED)
        ->target_type->toBe('TrainingPlan')
        ->target_id->toBe((string) $plan->id)
        ->actor_id->toBe($this->leader->id)
        ->reason->toBeNull()
        // A plan writes nothing to the reports until it is approved.
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse();
});

it('lets anyone commanding the platoon write it, an Admin too', function () {
    tpSave($this->commander, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    tpSave(User::factory()->admin()->create(), $this->platoon->id, ['2026-11-07' => 'Radio procedure']);

    expect(TrainingPlan::query()->sole()->revision)->toBe(2);
});

it('refuses a writer who does not command the platoon, a staff grant included', function (string $who) {
    $actor = match ($who) {
        'another platoon leader' => $this->otherLeader,
        'a section leader' => $this->sectionLeader,
        'a staff grant' => tap(User::factory()->serving()->create(), fn (User $u) => PortalRoleGrant::factory()->create(['user_id' => $u->id, 'unit_id' => $this->company->id])),
        'nobody' => User::factory()->create(),
    };

    expect(fn () => tpSave($actor, $this->platoon->id, ['2026-11-07' => 'Room clearing']))->toThrow(TrainingPlanForbiddenException::class)
        ->and(TrainingPlan::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->exists())->toBeFalse();
})->with(['another platoon leader', 'a section leader', 'a staff grant', 'nobody']);

it('refuses a plan the leader cannot save as given', function (Closure $save, string $message) {
    expect(fn () => $save($this))->toThrow(InvalidTrainingPlanException::class, $message)
        ->and(TrainingPlan::query()->exists())->toBeFalse();
})->with([
    'a unit that is not a platoon' => [fn ($t) => tpSave($t->commander, $t->company->id, ['2026-11-07' => 'Room clearing']), 'Only a platoon'],
    'a date that opens no week of the month' => [fn ($t) => tpSave($t->leader, $t->platoon->id, ['2026-12-05' => 'Room clearing']), 'does not open a Cav week'],
    'no subject at all' => [fn ($t) => tpSave($t->leader, $t->platoon->id, ['2026-11-07' => '  ']), 'at least one week'],
    'a month too far ahead' => [fn ($t) => tpSave($t->leader, $t->platoon->id, ['2027-11-06' => 'Room clearing'], false, '2027-11'), 'up to a year ahead'],
    'a month all before the floor' => [fn ($t) => tpSave($t->leader, $t->platoon->id, ['2026-01-03' => 'Room clearing'], false, '2026-01'), 'week floor'],
    'a month that is not one' => [fn ($t) => tpSave($t->leader, $t->platoon->id, ['2026-11-07' => 'Room clearing'], false, '2026-13'), 'week floor'],
]);

it('keeps a sent week\'s subject in the plan: it can no longer change, as its report cannot', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->platoon->id, 'week_start' => CarbonImmutable::parse('2026-10-17', 'UTC')]);

    expect(fn () => tpSave($this->leader, $this->platoon->id, ['2026-10-17' => 'Room clearing', '2026-10-24' => 'Radio procedure'], false, '2026-10'))
        ->toThrow(InvalidTrainingPlanException::class, 'already sent');

    // Left as it is (here: blank), the plan saves.
    expect(tpSave($this->leader, $this->platoon->id, ['2026-10-17' => '', '2026-10-24' => 'Radio procedure'], false, '2026-10')->weeks)->toHaveCount(1);
});

it('treats saving a plan exactly as it stands as a no-op, but resubmits one sent back', function () {
    $plan = tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    expect(AuditLogEntry::query()->count())->toBe(1);

    tpReturn($this->commander, $this->platoon->id, 'Add radios');
    $again = tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);

    expect($again->status)->toBe(TrainingPlanStatus::Pending)
        ->and($again->revision)->toBe($plan->revision + 1)
        ->and(AuditLogEntry::query()->count())->toBe(3);
});

it('lets the company commander approve, which sets the platoon topic for each planned week', function () {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing', '2026-11-21' => 'Radio procedure'], true);

    $plan = tpApprove($this->commander, $this->platoon->id);

    expect($plan->status)->toBe(TrainingPlanStatus::Approved)
        ->and($plan->reviewed_by)->toBe($this->commander->id)
        ->and(tpTopics($this->platoon->id))->toBe([['2026-11-07', 'Room clearing', true], ['2026-11-21', 'Radio procedure', true]])
        // The subject is the platoon leader's: the topic names them as its setter.
        ->and(PlatoonTrainingTopic::query()->pluck('set_by')->unique()->all())->toBe([$this->leader->id])
        ->and(AuditLogEntry::query()->latest('id')->first())
        ->action->toBe(ApproveTrainingPlanHandler::APPROVED)
        ->actor_id->toBe($this->commander->id)
        ->target_id->toBe((string) $plan->id);
});

it('writes a plan that is not enforced as a default the sections may change', function () {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    tpApprove($this->commander, $this->platoon->id);

    expect(tpTopics($this->platoon->id))->toBe([['2026-11-07', 'Room clearing', false]]);
});

it('lets battalion command and an Admin approve too', function (string $who) {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);

    $actor = $who === 'admin' ? User::factory()->admin()->create() : $this->battalionCommander;

    expect(tpApprove($actor, $this->platoon->id)->status)->toBe(TrainingPlanStatus::Approved);
})->with(['battalion', 'admin']);

it('refuses a reviewer who does not command the company, the platoon leader included', function (string $who) {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    $actor = match ($who) {
        'the platoon leader' => $this->leader,
        'another platoon leader' => $this->otherLeader,
        'a staff grant' => tap(User::factory()->serving()->create(), fn (User $u) => PortalRoleGrant::factory()->create(['user_id' => $u->id, 'unit_id' => $this->battalion->id])),
    };

    expect(fn () => tpApprove($actor, $this->platoon->id))->toThrow(TrainingPlanForbiddenException::class)
        ->and(fn () => tpReturn($actor, $this->platoon->id, 'No'))->toThrow(TrainingPlanForbiddenException::class)
        ->and(TrainingPlan::query()->sole()->status)->toBe(TrainingPlanStatus::Pending)
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse();
})->with(['the platoon leader', 'another platoon leader', 'a staff grant']);

it('lets a platoon leader who also commands the company approve their own plan', function () {
    $both = tpCommander($this->company->id, 799);

    tpSave($both, $this->platoon->id, ['2026-11-07' => 'Room clearing']);

    expect(tpApprove($both, $this->platoon->id)->status)->toBe(TrainingPlanStatus::Approved);
});

it('never changes a sent week\'s subject on approval, and leaves weeks before the floor alone', function () {
    // October: the 24th's platoon report is sent, with the topic it had then.
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->platoon->id, 'week_start' => CarbonImmutable::parse('2026-10-24', 'UTC')]);
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, CarbonImmutable::parse('2026-10-24', 'UTC'), 'What was sent', false, $this->leader->id);

    tpSave($this->leader, $this->platoon->id, ['2026-10-10' => 'Land navigation', '2026-10-17' => 'First aid', '2026-10-31' => 'Radio procedure'], false, '2026-10');

    // The floor moves up to one week back (the 17th) before the plan is approved: the 10th is now too far back.
    config(['portal.week_floor_weeks' => 1]);
    tpApprove($this->commander, $this->platoon->id, 1, '2026-10');

    expect(tpTopics($this->platoon->id))->toBe([
        ['2026-10-17', 'First aid', false],
        ['2026-10-24', 'What was sent', false],
        ['2026-10-31', 'Radio procedure', false],
    ]);
});

it('leaves a week the plan does not fill with the topic it had', function () {
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, CarbonImmutable::parse('2026-11-14', 'UTC'), 'Set by hand', true, $this->leader->id);

    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    tpApprove($this->commander, $this->platoon->id);

    expect(tpTopics($this->platoon->id))->toBe([['2026-11-07', 'Room clearing', false], ['2026-11-14', 'Set by hand', true]]);
});

it('needs approval again after a change, and keeps the approved subjects on the reports until then', function () {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    tpApprove($this->commander, $this->platoon->id);

    $plan = tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Radio procedure']);

    expect($plan->status)->toBe(TrainingPlanStatus::Pending)
        ->and(tpTopics($this->platoon->id))->toBe([['2026-11-07', 'Room clearing', false]]);

    tpApprove($this->commander, $this->platoon->id, 2);

    expect(tpTopics($this->platoon->id))->toBe([['2026-11-07', 'Radio procedure', false]]);
});

it('sends a plan back with the comment as the audit reason, and writes nothing to the reports', function () {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);

    $plan = tpReturn($this->commander, $this->platoon->id, '  Add a week on radios  ');

    expect($plan->status)->toBe(TrainingPlanStatus::Returned)
        ->and($plan->review_comment)->toBe('Add a week on radios')
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->latest('id')->first())
        ->action->toBe(ReturnTrainingPlanHandler::RETURNED)
        ->reason->toBe('Add a week on radios');
});

it('refuses a send-back without a comment', function () {
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);

    expect(fn () => tpReturn($this->commander, $this->platoon->id, '   '))->toThrow(ReasonRequiredException::class)
        ->and(TrainingPlan::query()->sole()->status)->toBe(TrainingPlanStatus::Pending);
});

it('refuses to review a plan that is missing, not pending, or changed since it was read', function () {
    expect(fn () => tpApprove($this->commander, $this->platoon->id))->toThrow(InvalidTrainingPlanException::class, 'no training plan');

    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Room clearing']);
    tpSave($this->leader, $this->platoon->id, ['2026-11-07' => 'Radio procedure']);

    // The reviewer read revision 1; the platoon has saved revision 2 since.
    expect(fn () => tpApprove($this->commander, $this->platoon->id, 1))->toThrow(InvalidTrainingPlanException::class, 'changed this plan')
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse();

    tpApprove($this->commander, $this->platoon->id, 2);

    expect(fn () => tpApprove($this->commander, $this->platoon->id, 2))->toThrow(InvalidTrainingPlanException::class, 'not waiting for review')
        ->and(fn () => tpReturn($this->commander, $this->platoon->id, 'Too late', 2))->toThrow(InvalidTrainingPlanException::class, 'not waiting for review')
        ->and(AuditLogEntry::query()->where('action', ApproveTrainingPlanHandler::APPROVED)->count())->toBe(1);
});
