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
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\PlatoonTrainingTopicRepository\Eloquent as EloquentPlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Repositories\WeeklyReportRepository\Eloquent as EloquentWeeklyReportRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
    // A topic is refused before the week floor, which counts back from now: pin now to this week.
    CarbonImmutable::setTestNow($this->moment);
    $this->leader = topicCommander($this->platoon->id, 801);
});

afterEach(fn () => CarbonImmutable::setTestNow());

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

it('treats a clear that a racing clear beat to it as the same clean no-op, not a 500', function () {
    setTopic($this->leader, $this->platoon->id, $this->moment, 'Room clearing');
    // The racing clear commits between this one's "is there a topic?" check and its own locked clear.
    app()->instance(PlatoonTrainingTopicRepository::class, new class extends EloquentPlatoonTrainingTopicRepository
    {
        private bool $raced = false;

        public function find(int $unitId, CarbonInterface $weekStart): ?PlatoonTrainingTopic
        {
            $found = parent::find($unitId, $weekStart);

            if (! $this->raced) {
                $this->raced = true;
                $this->clear($unitId, $weekStart);
            }

            return $found;
        }
    });

    expect(setTopic($this->leader, $this->platoon->id, $this->moment, null))->toBeNull()
        ->and(PlatoonTrainingTopic::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->pluck('action')->all())->toBe([SetPlatoonTrainingTopicHandler::SET]);
});

it('takes over a topic row a concurrent first set already inserted', function () {
    $racer = topicCommander($this->company->id, 806);
    $raced = false;
    // The racing set's insert commits just before this one's.
    DB::beforeExecuting(function (string $query) use ($racer, &$raced) {
        if (! $raced && str_starts_with(strtolower($query), 'insert into') && str_contains($query, 'platoon_training_topics')) {
            $raced = true;
            PlatoonTrainingTopic::query()->create(['unit_id' => $this->platoon->id, 'week_start' => $this->weekStart, 'subject' => 'Racer', 'set_by' => $racer->id]);
        }
    });

    $topic = setTopic($this->leader, $this->platoon->id, $this->moment, 'Room clearing', true);

    expect(PlatoonTrainingTopic::query()->sole())
        ->id->toBe($topic->id)
        ->subject->toBe('Room clearing')
        ->enforced->toBeTrue()
        ->set_by->toBe($this->leader->id);
});

it('refuses a set or a clear when the platoon\'s report is sent between its check and its write', function (?string $subject) {
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, $this->weekStart, 'Room clearing', false, $this->leader->id);
    $sender = $this->leader;
    // The send commits after the handler's early "already sent?" read.
    app()->instance(WeeklyReportRepository::class, new class($sender->id) extends EloquentWeeklyReportRepository
    {
        private bool $raced = false;

        public function __construct(private readonly int $senderId) {}

        public function find(int $unitId, CarbonInterface $weekStart): ?WeeklyReport
        {
            $found = parent::find($unitId, $weekStart);

            if (! $this->raced) {
                $this->raced = true;
                parent::send($unitId, $weekStart, [], null, $this->senderId, 'd', null);
            }

            return $found;
        }
    });

    expect(fn () => setTopic($this->leader, $this->platoon->id, $this->moment, $subject))
        ->toThrow(InvalidPlatoonTrainingTopicException::class);
    expect(PlatoonTrainingTopic::query()->sole()->subject)->toBe('Room clearing')
        ->and(AuditLogEntry::query()->exists())->toBeFalse();
})->with([
    'set' => ['Radio procedure'],
    'clear' => [null],
]);

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
