<?php

use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// AD-8: a staff grant reads an echelon, it never writes to it. The holder sees the roster, the
// rollcall, the reports and the game identities of the granted units, read-only, and every write
// route answers 403 with nothing written. Only command authority (a billet, or Admin) writes.

beforeEach(function () {
    // Thursday of the Cav week that opened Saturday 03Oct26.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $repo = app(UnitRepository::class);
    $this->company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);

    $this->member = Trooper::factory()->create(['username' => 'Member.M']);
    $this->member->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->member->id, 'platform' => 'hll', 'platform_id' => '7656119000000001']);

    $this->staff = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->staff->id, 'unit_id' => $this->company->id]);
});

it('lets a grant holder read the roster, the rollcall, the report and the game identities, each read-only', function () {
    $this->actingAs($this->staff)->get("/roster/{$this->section->id}")->assertOk();

    $this->actingAs($this->staff)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Rollcall')->has('troopers', 1)->where('canWrite', false));

    $this->actingAs($this->staff)->get("/reports/{$this->section->id}/2026-10-03")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Show')->where('canWrite', false));

    $this->actingAs($this->staff)->get('/game-identities')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('troopers', 1)->where('troopers.0.editable', false));
});

it('refuses a grant holder every write, writing nothing', function (string $method, Closure $uri, array $body) {
    Queue::fake();

    $this->actingAs($this->staff)->json($method, $uri($this), $body)->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(WeeklyReport::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0)
        ->and(TrooperGameIdentity::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with([
    'mark attendance' => ['POST', fn ($t) => "/rollcall/{$t->section->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => 0, 'status' => 'present']]]],
    'mark rollcall posted' => ['POST', fn ($t) => "/rollcall/{$t->section->id}/posted", ['week' => '2026-10-03']],
    'confirm practice' => ['POST', fn ($t) => "/rollcall/{$t->section->id}/confirm", ['week' => '2026-10-03']],
    'request capture' => ['POST', fn ($t) => "/rollcall/{$t->section->id}/capture", ['week' => '2026-10-03']],
    'link unmatched player' => ['POST', fn ($t) => "/rollcall/{$t->section->id}/unmatched/link", ['week' => '2026-10-03', 'platform' => 'hll', 'platform_id' => '7656119000000002', 'trooper_id' => 0]],
    'dismiss unmatched player' => ['POST', fn ($t) => "/rollcall/{$t->section->id}/unmatched/dismiss", ['week' => '2026-10-03', 'platform' => 'hll', 'platform_id' => '7656119000000002']],
    'save report draft' => ['PUT', fn ($t) => "/reports/{$t->section->id}/2026-10-03", ['notes' => 'staff notes']],
    'send report' => ['POST', fn ($t) => "/reports/{$t->section->id}/2026-10-03/send", ['reason' => 'staff send']],
    'set game identity' => ['PUT', fn ($t) => "/game-identities/{$t->member->id}/hll", ['player_name' => 'Staff', 'platform_id' => '7656119000000003']],
    'remove game identity' => ['DELETE', fn ($t) => "/game-identities/{$t->member->id}/hll", []],
]);

it('lets a leader write the unit they command but only read a unit they also hold a grant on', function () {
    $other = app(UnitRepository::class)->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $leader = User::factory()->commanding($other)->create();
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->section->id]);

    $this->actingAs($leader)->get("/rollcall/{$other->id}")->assertInertia(fn (Assert $page) => $page->where('canWrite', true));
    $this->actingAs($leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->where('canWrite', false));

    $this->actingAs($leader)->postJson("/rollcall/{$other->id}/posted", ['week' => '2026-10-03'])->assertOk();
    $this->actingAs($leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])->assertForbidden();

    expect(SectionPractice::query()->pluck('section_id')->all())->toBe([$other->id]);
});

it('keeps the pages writable for the unit\'s leader by billet and for an Admin', function (Closure $user) {
    $viewer = $user($this);

    $this->actingAs($viewer)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->where('canWrite', true));
    $this->actingAs($viewer)->get("/reports/{$this->section->id}/2026-10-03")->assertInertia(fn (Assert $page) => $page->where('canWrite', true));
    $this->actingAs($viewer)->get('/game-identities')->assertInertia(fn (Assert $page) => $page->where('troopers.0.editable', true));
})->with([
    'section leader' => [fn ($t) => User::factory()->commanding($t->section)->create()],
    'Admin' => [fn ($t) => User::factory()->admin()->create()],
]);
