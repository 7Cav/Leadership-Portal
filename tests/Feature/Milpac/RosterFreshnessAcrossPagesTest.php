<?php

use App\Models\PortalRoleGrant;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;

// AD-20, AD-4: Home, the roster, the milpac, rollcall and the weekly report read the roster's source and freshness
// from one reader (Milpac\RosterProvenance), so no page calls the cache current while another calls it stale.
// "Now" is Thu 08OCT26 16:20Z, inside the Cav week that opened Sat 03OCT26.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));

    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'path' => '900/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'name' => '1st Section', 'callsign' => '1/1/A/1-7', 'path' => '900/901/']);
    $this->trooper = Trooper::factory()->create();
    $this->trooper->forceFill(['primary_section_id' => $this->section->id])->save();

    $this->leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
});

/**
 * What each page says about the roster: its source label, the last good sync time, and whether it warns.
 *
 * @return array<string, array{source: string, at: ?string, warns: ?bool}>
 */
function rosterFreshnessOnEveryPage($test): array
{
    $props = fn (string $url) => $test->actingAs($test->leader)->get($url)->assertOk()->viewData('page')['props'];

    $home = $props('/home')['kpis']['roster'];
    $roster = $props("/roster/{$test->section->id}")['sync'];
    $milpac = $props("/troopers/{$test->trooper->id}")['sync'];
    $rollcall = $props("/rollcall/{$test->section->id}")['roster'];
    $report = $props("/reports/{$test->section->id}/2026-10-03")['rosterSync'];

    return [
        'home' => ['source' => $home['source'], 'at' => $home['syncedAt'], 'warns' => $home['state'] !== 'ok'],
        'roster' => ['source' => $roster['source'], 'at' => $roster['lastSuccessAt'], 'warns' => $roster['stale'] || $roster['lastSuccessAt'] === null],
        'milpac' => ['source' => $milpac['source'], 'at' => $milpac['lastSuccessAt'], 'warns' => $milpac['stale'] || $milpac['lastSuccessAt'] === null],
        // Rollcall and the report show the source and time only, no warning of their own.
        'rollcall' => ['source' => $rollcall['source'], 'at' => $rollcall['syncedAt'], 'warns' => null],
        'report' => ['source' => $report['source'], 'at' => $report['at'], 'warns' => null],
    ];
}

it('gives every page the same source, time and warning', function (array $runs, ?string $at, bool $warns) {
    foreach ($runs as [$status, $finishedAt]) {
        SyncRun::factory()->create(['source' => 'roster', 'status' => $status, 'finished_at' => $finishedAt]);
    }

    $pages = rosterFreshnessOnEveryPage($this);

    foreach ($pages as $page => $seen) {
        expect($seen['source'])->toBe('milpac', "{$page} source")
            ->and($seen['at'])->toBe($at, "{$page} time");

        if ($seen['warns'] !== null) {
            expect($seen['warns'])->toBe($warns, "{$page} warning");
        }
    }
})->with([
    'fresh' => [[[SyncRunStatus::Success, '2026-10-08 15:00:00']], '2026-10-08T15:00:00+00:00', false],
    'latest failed' => [[[SyncRunStatus::Success, '2026-10-08 14:00:00'], [SyncRunStatus::Failure, '2026-10-08 15:00:00']], '2026-10-08T14:00:00+00:00', true],
    'latest partial' => [[[SyncRunStatus::Success, '2026-10-08 14:00:00'], [SyncRunStatus::Partial, '2026-10-08 15:00:00']], '2026-10-08T14:00:00+00:00', true],
    'overdue' => [[[SyncRunStatus::Success, '2026-10-08 09:00:00']], '2026-10-08T09:00:00+00:00', true],
    'never' => [[], null, true],
]);

it('calls a partial run partial on Home, where it used to read as ok', function () {
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-08 14:00:00']);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 15:00:00']);

    $this->actingAs($this->leader)->get('/home')->assertInertia(fn ($page) => $page->where('kpis.roster.state', 'partial'));
});

it('flags an overdue sync on the roster and the milpac with no failed attempt to name', function () {
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-08 09:00:00']);

    $this->actingAs($this->leader)->get("/roster/{$this->section->id}")->assertInertia(fn ($page) => $page
        ->where('sync.stale', true)
        ->where('sync.lastAttempt', null));
    $this->actingAs($this->leader)->get("/troopers/{$this->trooper->id}")->assertInertia(fn ($page) => $page
        ->where('sync.stale', true)
        ->where('sync.lastAttempt', null));
});
