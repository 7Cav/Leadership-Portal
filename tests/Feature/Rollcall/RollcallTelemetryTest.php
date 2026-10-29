<?php

use App\Commands\Capture\CaptureSources;
use App\Jobs\CaptureSectionPracticeJob;
use App\Models\AuditLogEntry;
use App\Models\Game;
use App\Models\GameServer;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\TelemetryInterface;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

// 7.9 / AD-23, AD-20, AD-8: a practice with no server data says why, falls back to the manual flow, and can be retried.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'k'], 'services.battlemetrics' => [], 'services.nightwatch' => []]);
    app()->forgetInstance(CaptureSources::class);

    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '950/']);
    $this->leader = User::factory()->commanding($this->section)->create(['username' => 'Vasquez.R']);
    $this->ortega = Trooper::factory()->create(['username' => 'Ortega.M']);
    $this->ortega->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000001']);
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
});

function captureRun(object $test, SyncRunStatus $status, string $finishedAt = '2026-10-08 15:42:00', string $source = 'capture'): SyncRun
{
    return SyncRun::factory()->create([
        'source' => $source, 'status' => $status, 'started_at' => $finishedAt, 'finished_at' => $finishedAt,
        'section_practice_id' => $test->practice->id, 'error' => 'telemetry unavailable: crcon: unreachable',
    ]);
}

function telemetryOf(object $test)
{
    return $test->actingAs($test->leader)->get("/rollcall/{$test->section->id}");
}

it('shows nothing for a practice whose capture was never attempted', function () {
    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry', null)->where('capture', null));
});

it('shows the failure with its time and the unchanged manual marks when the last capture found no source', function () {
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page
        ->where('telemetry', ['state' => 'failed', 'platformLabel' => 'Hell Let Loose (WW2)', 'feeds' => ['crcon'], 'lastAttemptAt' => '2026-10-08T15:42:00+00:00', 'canRetry' => true, 'detail' => null])
        ->where('capture', null)
        ->where('unmatched', [])
        ->where('troopers.0.status', null)
        ->where('troopers.0.source', null));
});

it('reads the latest run of this practice only', function () {
    captureRun($this, SyncRunStatus::Failure, '2026-10-08 10:00:00');
    captureRun($this, SyncRunStatus::Success, '2026-10-08 12:00:00');
    $other = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-09-26 00:00:00']);
    SyncRun::factory()->create(['source' => 'capture', 'status' => SyncRunStatus::Failure, 'section_practice_id' => $other->id]);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'section_practice_id' => $this->practice->id, 'started_at' => '2026-10-08 15:00:00']);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry', null));
});

it('keeps showing the earlier failure while a retry is still running', function () {
    captureRun($this, SyncRunStatus::Failure);
    SyncRun::factory()->create(['source' => 'capture', 'status' => SyncRunStatus::InProgress, 'finished_at' => null, 'started_at' => '2026-10-08 16:19:00', 'section_practice_id' => $this->practice->id]);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'failed')->where('telemetry.lastAttemptAt', '2026-10-08T15:42:00+00:00'));
});

it('says telemetry is not configured when the game has no source with keys set, even after an old failure', function () {
    config(['services.crcon' => ['base_url' => null, 'api_key' => null]]);
    app()->forgetInstance(CaptureSources::class);
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page
        ->where('telemetry', ['state' => 'not_configured', 'platformLabel' => 'Hell Let Loose (WW2)', 'feeds' => [], 'lastAttemptAt' => null, 'canRetry' => false, 'detail' => null]));
});

it('names every feed of the game that was tried, in the order a capture tries them (#176)', function () {
    config(['services.battlemetrics' => ['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => '1']]);
    app()->forgetInstance(CaptureSources::class);
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.feeds', ['crcon', 'battlemetrics']));
});

it('names no feed when no game can be read for the section', function () {
    TrooperGameIdentity::query()->delete();
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.platformLabel', null)->where('telemetry.feeds', []));
});

it('treats a configured fallback as configured', function () {
    config(['services.crcon' => [], 'services.battlemetrics' => ['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => '1']]);
    app()->forgetInstance(CaptureSources::class);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry', null));
});

it('shows no unavailable state for a captured practice, whatever an earlier run did', function () {
    captureRun($this, SyncRunStatus::Failure);
    $this->practice->forceFill(['captured_at' => '2026-10-08 16:00:00', 'capture_source' => 'crcon', 'server_ref' => 'x'])->save();

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry', null)->where('capture.source', 'crcon'));
});

it('shows no state for a section with no linked game identity and no failure', function () {
    TrooperGameIdentity::query()->delete();

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry', null));
});

it('shows a failure but offers no retry when no game can be read for the section', function () {
    TrooperGameIdentity::query()->delete();
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'failed')->where('telemetry.canRetry', false));
});

it('picks the game most of the section has linked', function () {
    $second = Trooper::factory()->create();
    $second->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $second->id, 'game_id' => game('arma')->id, 'platform_id' => 'a1']);
    $third = Trooper::factory()->create();
    $third->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $third->id, 'game_id' => game('arma')->id, 'platform_id' => 'a2']);
    captureRun($this, SyncRunStatus::Failure);

    // Arma reads BattleMetrics only, which has no keys here: not configured, named for Arma.
    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'not_configured')->where('telemetry.platformLabel', 'Arma Reforger'));
});

it('breaks a tie between games by the game added first: HLL over Arma', function () {
    $second = Trooper::factory()->create();
    $second->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $second->id, 'game_id' => game('arma')->id, 'platform_id' => 'a1']);
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.platformLabel', 'Hell Let Loose (WW2)'));
});

it('reads a game an Admin added through the interface picked for it, from its own selected server (#388, #389)', function () {
    $squad = Game::factory()->create(['name' => 'Squad', 'interface' => TelemetryInterface::Crcon]);
    TrooperGameIdentity::query()->update(['game_id' => $squad->id]);
    GameServer::factory()->trainingFor($squad->id)->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'squad-crcon']);
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page
        ->where('telemetry.state', 'failed')
        ->where('telemetry.platformLabel', 'Squad')
        ->where('telemetry.feeds', ['crcon'])
        ->where('telemetry.canRetry', true));
});

it('never reads the env server of another game for a game added with no server selected: not set up, and says why', function () {
    $squad = Game::factory()->create(['name' => 'Squad', 'interface' => TelemetryInterface::Crcon]);
    TrooperGameIdentity::query()->update(['game_id' => $squad->id]);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page
        ->where('telemetry.state', 'not_configured')
        ->where('telemetry.platformLabel', 'Squad')
        ->where('telemetry.detail', 'no_server'));
    tryAgain($this)->assertStatus(422)->assertJsonPath('message', 'Capture is not set up for Squad. Mark attendance by hand.');
});

it('follows an Admin changing the game\'s interface: set to none, the game is not configured and cannot be retried (#388)', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->patch('/settings/games/'.game('hll')->id, ['name' => 'Hell Let Loose (WW2)', 'id_kinds' => ['Steam ID'], 'interface' => 'none'])
        ->assertRedirect();
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'not_configured')->where('telemetry.platformLabel', 'Hell Let Loose (WW2)'));
    tryAgain($this)->assertStatus(422)->assertJsonPath('message', 'Capture is not set up for Hell Let Loose (WW2). Mark attendance by hand.');
});

it('still reads a retired game: retiring stops new links, not the capture of the ones made (#388)', function () {
    game('hll')->forceFill(['retired_at' => now()])->save();
    captureRun($this, SyncRunStatus::Failure);

    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.platformLabel', 'Hell Let Loose (WW2)')->where('telemetry.canRetry', true));
});

function tryAgain(object $test, array $body = ['week' => '2026-10-03'], ?User $as = null)
{
    return ($as ? $test->actingAs($as) : $test->actingAs($test->leader))->postJson("/rollcall/{$test->section->id}/capture", $body);
}

it('queues a capture of the practice for the leader, bounded to the week so far, and audits the request', function () {
    Queue::fake();
    captureRun($this, SyncRunStatus::Failure);

    tryAgain($this)->assertStatus(202)->assertJson(['queued' => true]);

    Queue::assertPushed(CaptureSectionPracticeJob::class, function (CaptureSectionPracticeJob $job) {
        $c = $job->command;

        return $c->sectionPracticeId === $this->practice->id
            && $c->gameId === game('hll')->id
            && $c->triggeredBy === $this->leader->id
            && $c->windowStart->format('c') === '2026-10-03T00:00:00+00:00'
            && $c->windowEnd->format('c') === '2026-10-08T16:20:00+00:00';
    });
    expect(AuditLogEntry::query()->where('action', 'practice.capture_requested')->count())->toBe(1);
});

// S-M1: the server offers "Try again" on the same terms as the screen (`telemetry.canRetry`).

it('refuses a retry the screen would not offer: no practice, never attempted, last run ok, or already captured', function (Closure $arrange) {
    Queue::fake();
    $arrange($this);

    tryAgain($this)->assertStatus(422)->assertJsonPath('message', 'There is no failed capture to try again for this week.');

    Queue::assertNothingPushed();
    expect(AuditLogEntry::query()->where('action', 'practice.capture_requested')->count())->toBe(0)
        ->and(SectionPractice::query()->where('section_id', $this->section->id)->count())->toBeLessThanOrEqual(1);
})->with([
    'no practice row for the week' => [fn (object $test) => $test->practice->delete()],
    'never attempted' => [fn () => null],
    'the last run succeeded' => [fn (object $test) => captureRun($test, SyncRunStatus::Success)],
    'already captured' => [function (object $test) {
        captureRun($test, SyncRunStatus::Failure);
        $test->practice->forceFill(['captured_at' => '2026-10-08 16:00:00', 'capture_source' => 'crcon', 'server_ref' => 'x'])->save();
    }],
]);

it('refuses a retry of an old week whose capture never failed, and allows one whose capture did', function () {
    Queue::fake();
    $old = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-08-01 00:00:00']);

    tryAgain($this, ['week' => '2026-08-01'])->assertStatus(422);

    SyncRun::factory()->create(['source' => 'capture', 'status' => SyncRunStatus::Failure, 'section_practice_id' => $old->id, 'started_at' => '2026-08-05 20:00:00', 'finished_at' => '2026-08-05 20:00:00']);

    tryAgain($this, ['week' => '2026-08-01'])->assertStatus(202);
    Queue::assertPushed(CaptureSectionPracticeJob::class, 1);
});

it('refuses a retry for a week whose report has been sent, as locked, even after a failed capture', function () {
    Queue::fake();
    captureRun($this, SyncRunStatus::Failure);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => '2026-10-03']);

    tryAgain($this)->assertStatus(409)->assertJson(['locked' => true, 'message' => 'The report for this week has been sent, so its rollcall is locked.']);

    Queue::assertNothingPushed();
    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('throttles the capture route per leader, on top of the handler\'s per-practice throttle', function () {
    Queue::fake();
    captureRun($this, SyncRunStatus::Failure);

    tryAgain($this)->assertStatus(202);

    // Requests 2-10 reach the handler, which answers its own per-practice 429 with the wait.
    foreach (range(2, 10) as $i) {
        tryAgain($this)->assertStatus(429)->assertJsonStructure(['retryAfter']);
    }

    // The 11th is stopped by the route limiter before the handler runs.
    tryAgain($this)->assertStatus(429)
        ->assertJsonPath('message', 'Too many capture requests. Try again in a minute.')
        ->assertJsonMissingPath('retryAfter');
});

it('caps the window at the end of a past week', function () {
    Queue::fake();
    $past = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-09-26 00:00:00']);
    SyncRun::factory()->create(['source' => 'capture', 'status' => SyncRunStatus::Failure, 'section_practice_id' => $past->id, 'started_at' => '2026-10-01 20:00:00', 'finished_at' => '2026-10-01 20:00:00']);

    tryAgain($this, ['week' => '2026-09-26'])->assertStatus(202);

    Queue::assertPushed(CaptureSectionPracticeJob::class, fn (CaptureSectionPracticeJob $job) => $job->command->windowEnd->format('c') === '2026-10-03T00:00:00+00:00');
});

it('refuses a retry outside the leader scope without queuing anything', function () {
    Queue::fake();
    $outsider = User::factory()->serving()->create();

    tryAgain($this, as: $outsider)->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses a retry when the game has no keys set, saying so', function () {
    Queue::fake();
    config(['services.crcon' => []]);
    app()->forgetInstance(CaptureSources::class);

    tryAgain($this)->assertStatus(422)->assertJsonPath('message', 'Capture is not set up for Hell Let Loose (WW2). Mark attendance by hand.');

    Queue::assertNothingPushed();
});

it('refuses a retry for a section with no game identity linked', function () {
    Queue::fake();
    TrooperGameIdentity::query()->delete();

    tryAgain($this)->assertStatus(422);

    Queue::assertNothingPushed();
});

it('refuses a missing week and a future week', function () {
    Queue::fake();

    tryAgain($this, [])->assertStatus(422);
    tryAgain($this, ['week' => '2026-10-10'])->assertStatus(422);

    Queue::assertNothingPushed();
});

it('refuses a second request for the same practice within the throttle window, with the wait', function () {
    Queue::fake();
    RateLimiter::clear('practice-capture-retry:'.$this->section->id.':2026-10-03');
    captureRun($this, SyncRunStatus::Failure);

    tryAgain($this)->assertStatus(202);
    tryAgain($this)->assertStatus(429)->assertJsonStructure(['message', 'retryAfter']);

    Queue::assertPushed(CaptureSectionPracticeJob::class, 1);
});

it('does not use up the throttle on a refused request', function () {
    Queue::fake();
    captureRun($this, SyncRunStatus::Failure);
    config(['services.crcon' => []]);
    app()->forgetInstance(CaptureSources::class);
    tryAgain($this)->assertStatus(422);

    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'k']]);
    app()->forgetInstance(CaptureSources::class);

    tryAgain($this)->assertStatus(202);
});

it('answers a queue failure as an error, never as success', function () {
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue is down'));
    captureRun($this, SyncRunStatus::Failure);

    tryAgain($this)->assertStatus(500)->assertJsonPath('message', fn (string $m) => str_contains($m, 'could not be queued'));
});

it('runs the queued retry through to a visible failure on the next page load', function () {
    // The sync queue (phpunit.xml) runs the job inline: every source down -> a failed run the screen shows.
    config(['services.crcon' => ['base_url' => 'http://127.0.0.1:9', 'api_key' => 'k']]);
    app()->forgetInstance(CaptureSources::class);
    $earlier = captureRun($this, SyncRunStatus::Failure, '2026-10-08 10:00:00');

    tryAgain($this)->assertStatus(202);

    $run = SyncRun::query()->where('source', 'capture')->whereKeyNot($earlier->id)->sole();
    expect($run->status)->toBe(SyncRunStatus::Failure)->and($run->triggered_by)->toBe((string) $this->leader->id);
    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'failed')->where('capture', null));
});
