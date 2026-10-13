<?php

use App\Commands\Capture\CaptureSources;
use App\Jobs\CaptureSectionPracticeJob;
use App\Models\AuditLogEntry;
use App\Models\GamePlatform;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
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
    $this->leader = User::factory()->serving()->create(['username' => 'Vasquez.R']);
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    $this->ortega = Trooper::factory()->create(['username' => 'Ortega.M']);
    $this->ortega->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'platform' => GamePlatform::Hll, 'platform_id' => '7656119000000001']);
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
        ->where('telemetry', ['state' => 'failed', 'platformLabel' => 'Hell Let Loose', 'lastAttemptAt' => '2026-10-08T15:42:00+00:00', 'canRetry' => true])
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
        ->where('telemetry', ['state' => 'not_configured', 'platformLabel' => 'Hell Let Loose', 'lastAttemptAt' => null, 'canRetry' => false]));
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
    TrooperGameIdentity::factory()->create(['trooper_id' => $second->id, 'platform' => GamePlatform::Arma, 'platform_id' => 'a1']);
    $third = Trooper::factory()->create();
    $third->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $third->id, 'platform' => GamePlatform::Arma, 'platform_id' => 'a2']);
    captureRun($this, SyncRunStatus::Failure);

    // Arma reads BattleMetrics only, which has no keys here: not configured, named for Arma.
    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'not_configured')->where('telemetry.platformLabel', 'Arma Reforger'));
});

function tryAgain(object $test, array $body = ['week' => '2026-10-03'], ?User $as = null)
{
    return ($as ? $test->actingAs($as) : $test->actingAs($test->leader))->postJson("/rollcall/{$test->section->id}/capture", $body);
}

it('queues a capture of the practice for the leader, bounded to the week so far, and audits the request', function () {
    Queue::fake();

    tryAgain($this)->assertStatus(202)->assertJson(['queued' => true]);

    Queue::assertPushed(CaptureSectionPracticeJob::class, function (CaptureSectionPracticeJob $job) {
        $c = $job->command;

        return $c->sectionPracticeId === $this->practice->id
            && $c->platform === GamePlatform::Hll
            && $c->triggeredBy === $this->leader->id
            && $c->windowStart->format('c') === '2026-10-03T00:00:00+00:00'
            && $c->windowEnd->format('c') === '2026-10-08T16:20:00+00:00';
    });
    expect(AuditLogEntry::query()->where('action', 'practice.capture_requested')->count())->toBe(1);
});

it('creates the practice row when the week has none yet', function () {
    Queue::fake();
    $this->practice->delete();

    tryAgain($this)->assertStatus(202);

    expect(SectionPractice::query()->where('section_id', $this->section->id)->count())->toBe(1);
    Queue::assertPushed(CaptureSectionPracticeJob::class);
});

it('caps the window at the end of a past week', function () {
    Queue::fake();
    SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-09-26 00:00:00']);

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

    tryAgain($this)->assertStatus(422)->assertJsonPath('message', 'Telemetry is not configured for Hell Let Loose. Mark attendance by hand.');

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

    tryAgain($this)->assertStatus(202);
    tryAgain($this)->assertStatus(429)->assertJsonStructure(['message', 'retryAfter']);

    Queue::assertPushed(CaptureSectionPracticeJob::class, 1);
});

it('does not use up the throttle on a refused request', function () {
    Queue::fake();
    config(['services.crcon' => []]);
    app()->forgetInstance(CaptureSources::class);
    tryAgain($this)->assertStatus(422);

    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'k']]);
    app()->forgetInstance(CaptureSources::class);

    tryAgain($this)->assertStatus(202);
});

it('answers a queue failure as an error, never as success', function () {
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue is down'));

    tryAgain($this)->assertStatus(500)->assertJsonPath('message', fn (string $m) => str_contains($m, 'could not be queued'));
});

it('runs the queued retry through to a visible failure on the next page load', function () {
    // The sync queue (phpunit.xml) runs the job inline: every source down -> a failed run the screen shows.
    config(['services.crcon' => ['base_url' => 'http://127.0.0.1:9', 'api_key' => 'k']]);
    app()->forgetInstance(CaptureSources::class);

    tryAgain($this)->assertStatus(202);

    $run = SyncRun::query()->where('source', 'capture')->sole();
    expect($run->status)->toBe(SyncRunStatus::Failure)->and($run->triggered_by)->toBe((string) $this->leader->id);
    telemetryOf($this)->assertInertia(fn (Assert $page) => $page->where('telemetry.state', 'failed')->where('capture', null));
});
