<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Jobs\CaptureSectionPracticeJob;
use App\Jobs\SyncRosterJob;
use App\Models\AuditLogEntry;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\ActingSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// Work queued while acting as someone (#379, #432) runs later in a worker, where the request's `ActingFor` is gone. The
// queued capture and the unit roster sync must still name who really asked: the Admin or leader, on behalf of the
// person (or in a billet's place), never the person acted as on their own.
//
//   Alpha Company (CO 6000)
//     1st Platoon (PL 6100)
//       1st Section (SL 6200), with Ortega.M placed in it

function qwPerson(int $cavId, string $username, ?int $position): User
{
    $trooper = Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $position, 'status' => TrooperStatus::Combat, 'rank' => 'SGT', 'username' => $username]);

    return User::factory()->create(['cav_id' => $cavId, 'forum_user_id' => $trooper->forum_user_id, 'username' => $username]);
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'k'], 'services.battlemetrics' => [], 'services.nightwatch' => []]);
    app()->forgetInstance(CaptureSources::class);

    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    foreach ([6000 => $this->company, 6100 => $this->platoon, 6200 => $this->section] as $position => $unit) {
        UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $position]);
    }

    $this->co = qwPerson(900, 'Okafor.T', 6000);
    $this->pl = qwPerson(901, 'Hale.S', 6100);
    $this->sl = qwPerson(903, 'Vasquez.R', 6200);
    $this->admin = User::factory()->admin()->create(['username' => 'Root.R']);

    $this->ortega = Trooper::factory()->create(['username' => 'Ortega.M', 'status' => TrooperStatus::Combat, 'primary_section_id' => $this->section->id]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000001']);
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
    // A failed capture, so the screen (and the server) offer "Try again".
    SyncRun::factory()->create([
        'source' => 'capture', 'status' => SyncRunStatus::Failure, 'started_at' => '2026-10-08 15:42:00', 'finished_at' => '2026-10-08 15:42:00',
        'section_practice_id' => $this->practice->id, 'error' => 'telemetry unavailable: crcon: unreachable',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** Start acting as `$as` (a person) or as the billet of `$as` (a unit), as `$who`. */
function qwActAs(object $test, User $who, User|Unit $as): void
{
    $body = $as instanceof Unit ? ['kind' => 'unit', 'id' => $as->id] : ['kind' => 'user', 'id' => $as->id];
    $test->actingAs($who)->post('/act-as', $body)->assertRedirect('/home');
}

/**
 * Run the one queued capture the way a worker does: a fresh scope (nothing from the request survives), with a source
 * that sees Ortega on the server.
 */
function qwRunQueuedCapture(): void
{
    $job = Queue::pushed(CaptureSectionPracticeJob::class)->sole();

    app()->forgetScopedInstances();
    app()->instance(CaptureSources::class, new CaptureSources([game('hll')->interface->value => [
        new CaptureSource('crcon', 'crcon-server-1', fn ($start, $end) => new CrconPresence(
            [new CrconPlayer('Ortega', '7656119000000001')], $start, $end, CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC')->toDateTimeImmutable(),
        )),
    ]]));

    app()->call([$job, 'handle']);
}

describe('a queued capture ("Try again")', function () {
    it('names the real actor, on behalf of the person acted as', function (string $who) {
        Queue::fake();
        qwActAs($this, $this->{$who}, $this->sl);

        $this->actingAs($this->{$who})->postJson("/rollcall/{$this->section->id}/capture", ['week' => '2026-10-03'])->assertStatus(202);
        qwRunQueuedCapture();

        foreach (['practice.capture_requested', 'practice.captured', 'attendance.auto_marked'] as $action) {
            $entry = AuditLogEntry::where('action', $action)->sole();
            expect($entry->actor_id)->toBe($this->{$who}->id, $action)
                ->and($entry->on_behalf_of_id)->toBe($this->sl->id, $action)
                ->and($entry->on_behalf_of_unit_id)->toBeNull();
        }
        expect(SyncRun::where('source', 'capture')->latest('id')->first()->triggered_by)->toBe((string) $this->{$who}->id)
            ->and($this->practice->fresh()->captured_at)->not->toBeNull();
    })->with(['an Admin acting as the section leader' => ['admin'], 'the platoon leader acting as them' => ['pl']]);

    it('names the Admin in the billet\'s place when they act as the section\'s billet', function () {
        Queue::fake();
        qwActAs($this, $this->admin, $this->section);

        $this->actingAs($this->admin)->postJson("/rollcall/{$this->section->id}/capture", ['week' => '2026-10-03'])->assertStatus(202);
        qwRunQueuedCapture();

        foreach (['practice.capture_requested', 'practice.captured', 'attendance.auto_marked'] as $action) {
            $entry = AuditLogEntry::where('action', $action)->sole();
            expect($entry->actor_id)->toBe($this->admin->id, $action)
                ->and($entry->on_behalf_of_id)->toBeNull()
                ->and($entry->on_behalf_of_unit_id)->toBe($this->section->id, $action);
        }
    });

    it('names the leader alone when they ask in their own name', function () {
        Queue::fake();

        $this->actingAs($this->sl)->postJson("/rollcall/{$this->section->id}/capture", ['week' => '2026-10-03'])->assertStatus(202);
        expect(Queue::pushed(CaptureSectionPracticeJob::class)->sole()->command->acting)->toBeNull();
        qwRunQueuedCapture();

        $entry = AuditLogEntry::where('action', 'practice.captured')->sole();
        expect($entry->actor_id)->toBe($this->sl->id)->and($entry->on_behalf_of_id)->toBeNull();
    });

    it('puts back what was held before once the run is done, for a run on the sync queue', function () {
        app()->instance(CaptureSources::class, new CaptureSources([game('hll')->interface->value => []]));
        $acting = app(ActingFor::class);
        $acting->set($this->pl->id, $this->sl->id);
        $start = CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC')->toDateTimeImmutable();

        app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice(
            $this->practice->id, game('hll')->id, $start, $start, $this->co->id, new ActingSnapshot($this->admin->id, $this->co->id),
        ));

        expect($acting->admin())->toBe($this->pl->id)->and($acting->adminActingAs($this->sl->id))->toBe($this->pl->id);
    });
});

describe('a unit roster sync', function () {
    beforeEach(function () {
        MockClient::destroyGlobal();
        config(['services.cav.base_url' => 'https://api.7cav.us', 'services.cav.api_key' => 'cav7_test-key']);
        MockClient::global([
            'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => [
                '901' => [
                    'user' => ['userId' => '901', 'username' => 'Hale.S'],
                    'rank' => ['rankShort' => 'SGT', 'rankFull' => 'Sergeant', 'rankImageUrl' => '', 'rankId' => '4'],
                    'realName' => 'Real Name', 'uniformUrl' => '', 'roster' => 'ROSTER_TYPE_COMBAT',
                    'primary' => ['positionTitle' => 'Platoon Leader', 'positionId' => '6100'],
                    'secondaries' => [], 'joinDate' => '2020-01-01', 'promotionDate' => '2022-01-01', 'discordId' => '',
                    'lastForumPostDate' => '', 'mos' => '11B', 'consoleGamertag' => '',
                ],
            ]]),
            'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => []]),
            'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => []]),
        ]);
    });

    afterEach(fn () => MockClient::destroyGlobal());

    it('is triggered by the real actor, not the person acted as', function (string $who) {
        Queue::fake();
        qwActAs($this, $this->{$who}, $this->pl);

        $this->actingAs($this->{$who})->post("/units/{$this->section->id}/roster-sync")->assertRedirect();

        $job = Queue::pushed(SyncRosterJob::class)->sole();
        expect($job->triggeredBy)->toBe($this->{$who}->id);

        app()->forgetScopedInstances();
        app()->call([$job, 'handle']);

        expect(SyncRun::where('unit_id', $this->section->id)->sole()->triggered_by)->toBe((string) $this->{$who}->id);
    })->with(['an Admin acting as the platoon leader' => ['admin'], 'the commander acting as them' => ['co']]);

    it('is triggered by the leader when they sync in their own name', function () {
        Queue::fake();

        $this->actingAs($this->pl)->post("/units/{$this->section->id}/roster-sync")->assertRedirect();

        expect(Queue::pushed(SyncRosterJob::class)->sole()->triggeredBy)->toBe($this->pl->id);
    });
});
