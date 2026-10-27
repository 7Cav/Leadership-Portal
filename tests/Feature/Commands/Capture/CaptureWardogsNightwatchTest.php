<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSources;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Crcon\CrconApi;
use App\Integration\Nightwatch\NightwatchApi;
use App\Models\Attendance;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// Story 7.10: the capture run reads WARDOGS presence from Nightwatch. Real seam, Saloon-faked, invented data.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);

    $crcon = ['base_url' => 'https://crcon.example.test', 'api_key' => null];
    $bm = ['server_id' => '1', 'api_key' => null];
    $nw = ['base_url' => 'https://nw.test/api/public/stats', 'api_key' => 'test-key', 'server_ids' => 'srv-1'];
    app()->instance(CaptureSources::class, CaptureSources::default(new CrconApi($crcon), new BattleMetricsApi($bm), $crcon, $bm, new NightwatchApi($nw), $nw));
});

afterEach(fn () => Carbon::setTestNow());

function captureWardogs(object $test)
{
    return app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice(
        $test->practice->id,
        game('wardogs'),
        CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC')->toDateTimeImmutable(),
        CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC')->toDateTimeImmutable(),
        'system',
    ));
}

it('captures a WARDOGS practice from Nightwatch, resolving via the wardogs identity', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $mine->id, 'game_id' => game('wardogs')->id, 'platform_id' => '76561190000000111']);
    // Same id on another platform must not match a WARDOGS player.
    $other = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $other->id, 'game_id' => game('hll')->id, 'platform_id' => '76561190000000222']);

    MockClient::global(['nw.test/api/public/stats/matches*' => MockResponse::make(['matches' => [[
        'id' => 'm-1', 'serverId' => 'srv-1', 'startedAtUtc' => '2026-10-08T17:30:00Z', 'endedAtUtc' => '2026-10-08T19:00:00Z', 'live' => false,
        'board' => ['scoreboard' => [
            ['steamId' => '76561190000000111', 'name' => 'Alpha'],
            ['steamId' => '76561190000000222', 'name' => 'Bravo'],
            ['steamId' => '76561190000000333', 'name' => 'Charlie'],
        ]],
    ]]])]);

    $result = captureWardogs($this);
    $practice = $this->practice->fresh();

    expect($result->captured)->toBeTrue()
        ->and($practice->capture_source)->toBe('nightwatch')
        ->and($practice->server_ref)->toBe('nightwatch:srv-1')
        ->and($practice->captured_at)->not->toBeNull()
        ->and(array_column($result->matched, 'trooperId'))->toBe([$mine->id])
        ->and($result->unmatchedCount())->toBe(2)
        ->and(Attendance::query()->count())->toBe(1);
});

it('leaves the practice un-captured when Nightwatch is unavailable', function () {
    MockClient::global(['nw.test/api/public/stats/matches*' => MockResponse::make([], 503)]);

    $result = captureWardogs($this);
    $practice = $this->practice->fresh();

    expect($result->captured)->toBeFalse()
        ->and($practice->capture_source)->toBeNull()
        ->and($practice->captured_at)->toBeNull()
        ->and(Attendance::query()->count())->toBe(0)
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failure);
});

it('leaves the practice un-captured when the window is past Nightwatch retention', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
    MockClient::global(['nw.test/api/public/stats/matches*' => MockResponse::make(['matches' => []])]);

    $result = captureWardogs($this);

    expect($result->captured)->toBeFalse()
        ->and($result->unavailableReasons[0])->toContain('window expired')
        ->and($this->practice->fresh()->capture_source)->toBeNull()
        ->and(Attendance::query()->count())->toBe(0);
});
