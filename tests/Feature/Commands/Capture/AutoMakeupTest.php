<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Practices\InvalidPresenceMinimumException;
use App\Commands\Practices\PresenceMinimum;
use App\Commands\Practices\PresenceMinimumForbiddenException;
use App\Commands\Practices\SetPresenceMinimum;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\GameServer;
use App\Models\Loa;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallRoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// #558: a capture of a practice with a slot gives a trooper from another section who was on the server for the
// presence minimum an automatic make-up on their own roll call, records the sighting, and never overrides a leader.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC')); // Thursday; the Cav week opened 24OCT26
    $this->week = CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC');
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $units->setGame($this->battalion, game('hll')->id);
    $company = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $this->battalion->id);
    $first = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $company->id);
    $second = $units->create(UnitType::Platoon, '2nd Platoon', '2/B/2-7', $company->id);
    $this->home = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $first->id);
    $this->host = $units->create(UnitType::Section, '1st Section', '1/2/B/2-7', $second->id);
    $this->sharing = $units->create(UnitType::Section, '2nd Section', '2/2/B/2-7', $second->id);

    // The host and its sister section share Wed 2359Z; the guest's own section practises Thu 0200Z.
    SectionPracticeSlot::query()->create(['section_id' => $this->host->id, 'weekday' => 3, 'start_minute' => 1439]);
    SectionPracticeSlot::query()->create(['section_id' => $this->sharing->id, 'weekday' => 3, 'start_minute' => 1439]);
    SectionPracticeSlot::query()->create(['section_id' => $this->home->id, 'weekday' => 4, 'start_minute' => 120]);

    $this->hostPractice = app(SectionPracticeRepository::class)->findOrCreate($this->host->id, $this->week);
    $this->start = CarbonImmutable::parse('2026-10-28 23:59:00', 'UTC')->toDateTimeImmutable();
    $this->end = CarbonImmutable::parse('2026-10-29 00:59:00', 'UTC')->toDateTimeImmutable();

    $this->guest = makeupTrooper($this->home, '76561190000000001');
    $this->leader = User::factory()->commanding($this->home)->create();
});

function makeupTrooper(Unit $section, string $platformId, TrooperStatus $status = TrooperStatus::Combat): Trooper
{
    $trooper = Trooper::factory()->create(['status' => $status]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game('hll')->id, 'platform_id' => $platformId]);

    return $trooper;
}

/** Capture the host practice with these players (each a CrconPlayer) from one or more servers' answers. */
function captureHost(object $t, array ...$servers)
{
    $sources = array_map(fn (array $players) => new CaptureSource('crcon', 'crcon-'.count($players), fn ($start, $end) => new CrconPresence($players, $start, $end, CarbonImmutable::now()->toDateTimeImmutable())), $servers ?: [[]]);
    app()->instance(CaptureSources::class, new CaptureSources([game('hll')->interface->value => $sources]));

    return app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice($t->hostPractice->id, game('hll')->id, $t->start, $t->end));
}

function homeRow(object $t, ?Trooper $trooper = null): ?Attendance
{
    $practice = SectionPractice::query()->where('section_id', $t->home->id)->where('week_start', $t->week)->first();

    return $practice === null ? null : Attendance::query()->where('section_practice_id', $practice->id)->where('trooper_id', ($trooper ?? $t->guest)->id)->first();
}

function seen(string $id, ?int $minutes): CrconPlayer
{
    return new CrconPlayer("P{$id}", $id, minutes: $minutes);
}

it('gives a make-up on their own roll call to a trooper on the host\'s server for the minimum, and records the sighting', function () {
    captureHost($this, [seen('76561190000000001', 45)]);

    $row = homeRow($this);
    expect($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Auto);

    $sighting = CrossSectionSighting::query()->sole();
    expect($sighting->trooper_id)->toBe($this->guest->id)
        ->and($sighting->section_practice_id)->toBe($this->hostPractice->id)
        ->and($sighting->source)->toBe(CrossSectionSighting::SOURCE_CAPTURE);

    $run = SyncRun::query()->where('source', 'capture')->sole();
    expect($run->updated)->toBe(1)->and($run->error)->toContain('make-ups 1 given');
});

it('gives nothing under the minimum, at exactly the minimum gives the make-up', function (int $minutes, bool $makeup) {
    captureHost($this, [seen('76561190000000001', $minutes)]);

    expect(homeRow($this)?->status === AttendanceStatus::Makeup)->toBe($makeup)
        ->and(CrossSectionSighting::query()->exists())->toBe($makeup);
})->with([[39, false], [40, true], [60, true]]);

it('adds up the minutes on two servers, so a trooper who moved servers mid-practice still counts', function () {
    $a = GameServer::factory()->trainingFor(game('hll')->id)->create(['name' => 'Training A']);
    $b = GameServer::factory()->trainingFor(game('hll')->id)->create(['name' => 'Training B']);
    $on = fn (GameServer $server, int $minutes) => new CaptureServer($server->id, $server->name, [new CaptureSource('crcon', "crcon:{$server->external_id}",
        fn ($start, $end) => new CrconPresence([seen('76561190000000001', $minutes)], $start, $end, CarbonImmutable::now()->toDateTimeImmutable()), true, $server->id)]);
    app()->instance(CaptureSources::class, new CaptureSources([], fn () => [$on($a, 20), $on($b, 25)]));

    app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice($this->hostPractice->id, game('hll')->id, $this->start, $this->end));

    expect(homeRow($this)->status)->toBe(AttendanceStatus::Makeup);
});

it('never gives a make-up from a source that only knows who was seen', function () {
    captureHost($this, [seen('76561190000000001', null)]);

    expect(homeRow($this))->toBeNull()->and(CrossSectionSighting::query()->exists())->toBeFalse();
});

it('gives no make-up to a trooper whose own section shares the slot: it was their own practice', function () {
    $sister = makeupTrooper($this->sharing, '76561190000000002');

    captureHost($this, [seen('76561190000000002', 60)]);

    expect(CrossSectionSighting::query()->exists())->toBeFalse()
        ->and(Attendance::query()->where('trooper_id', $sister->id)->exists())->toBeFalse();
});

it('pre-marks the host\'s own trooper present only for the minimum, and as before from a source without minutes', function () {
    $short = makeupTrooper($this->host, '76561190000000003');
    $long = makeupTrooper($this->host, '76561190000000004');
    $unknown = makeupTrooper($this->host, '76561190000000005');

    captureHost($this, [seen('76561190000000003', 30), seen('76561190000000004', 41), seen('76561190000000005', null)]);

    $marks = Attendance::query()->where('section_practice_id', $this->hostPractice->id)->pluck('status', 'trooper_id');
    expect($marks->has($short->id))->toBeFalse()
        ->and($marks[$long->id])->toBe(AttendanceStatus::Present)
        ->and($marks[$unknown->id])->toBe(AttendanceStatus::Present);
});

it('never overwrites a leader\'s mark across repeated runs, and turns an automatic miss into the make-up', function (AttendanceStatus $status, AttendanceSource $source, AttendanceStatus $after) {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->home->id, $this->week);
    Attendance::factory()->create(['trooper_id' => $this->guest->id, 'section_practice_id' => $practice->id, 'status' => $status, 'source' => $source]);

    captureHost($this, [seen('76561190000000001', 55)]);
    captureHost($this, [seen('76561190000000001', 55)]);

    expect(homeRow($this)->status)->toBe($after)
        ->and(CrossSectionSighting::query()->count())->toBe(1);
})->with([
    'manual missed' => [AttendanceStatus::Missed, AttendanceSource::Manual, AttendanceStatus::Missed],
    'manual excused' => [AttendanceStatus::Excused, AttendanceSource::Manual, AttendanceStatus::Excused],
    'auto present' => [AttendanceStatus::Present, AttendanceSource::Auto, AttendanceStatus::Present],
    'auto missed' => [AttendanceStatus::Missed, AttendanceSource::Auto, AttendanceStatus::Makeup],
]);

it('does not put back a make-up a leader changed', function () {
    captureHost($this, [seen('76561190000000001', 50)]);
    app(MarkAttendanceHandler::class)->handle($this->leader, new MarkAttendance($this->home->id, $this->week->toDateTimeImmutable(),
        new AttendanceMark($this->guest->id, AttendanceStatus::Missed),
    ));

    captureHost($this, [seen('76561190000000001', 50)]);

    expect(homeRow($this)->status)->toBe(AttendanceStatus::Missed)
        ->and(homeRow($this)->source)->toBe(AttendanceSource::Manual);
});

it('writes nothing into a week whose own report was sent, but still records the sighting', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week]);

    captureHost($this, [seen('76561190000000001', 50)]);

    expect(homeRow($this))->toBeNull()
        ->and(CrossSectionSighting::query()->count())->toBe(1)
        ->and(SyncRun::query()->sole()->error)->toContain('1 locked');
});

it('gives nothing to a trooper on leave, one who left, an unmatched player or staff on no roll call', function () {
    Loa::factory()->create(['trooper_id' => $this->guest->id, 'starts_at' => $this->week->subDays(2), 'ends_at' => $this->week->addDays(9)]);
    $gone = makeupTrooper($this->home, '76561190000000006', TrooperStatus::Inactive);
    $staff = makeupTrooper($this->battalion, '76561190000000007');

    captureHost($this, [seen('76561190000000001', 60), seen('76561190000000006', 60), seen('76561190000000008', 60), seen('76561190000000007', 60)]);

    expect(Attendance::query()->where('status', AttendanceStatus::Makeup->value)->exists())->toBeFalse()
        ->and(CrossSectionSighting::query()->pluck('trooper_id')->all())->toBe([$this->guest->id])
        ->and($gone->id)->not->toBeNull()
        ->and($staff->id)->not->toBeNull();
});

it('gives no make-up from a capture over the week so far, as before slots', function () {
    $this->start = $this->week->toDateTimeImmutable();
    $this->end = CarbonImmutable::now()->toDateTimeImmutable();

    captureHost($this, [seen('76561190000000001', 600)]);

    expect(homeRow($this))->toBeNull()->and(CrossSectionSighting::query()->exists())->toBeFalse();
});

it('uses the battalion\'s own minimum, set by its HQ staff and audited', function () {
    $staff = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    $staff->forceFill(['primary_section_id' => $this->battalion->id])->save();
    $user = User::factory()->create(['cav_id' => $staff->cav_id]);

    app(SetPresenceMinimum::class)->handle($user, $this->battalion->id, 30);

    expect(app(PresenceMinimum::class)->forUnit($this->home->id))->toBe(30)
        ->and(AuditLogEntry::query()->where('action', 'presence_minimum.set')->count())->toBe(1);

    captureHost($this, [seen('76561190000000001', 35)]);
    expect(homeRow($this)->status)->toBe(AttendanceStatus::Makeup);

    // The same value again changes nothing and is not logged; null goes back to the default.
    app(SetPresenceMinimum::class)->handle($user, $this->battalion->id, 30);
    app(SetPresenceMinimum::class)->handle($user, $this->battalion->id, null);
    expect(AuditLogEntry::query()->where('action', 'presence_minimum.set')->count())->toBe(2)
        ->and(app(PresenceMinimum::class)->forUnit($this->home->id))->toBe(40);
});

it('lets an Admin set the minimum and refuses a section leader, a unit that is not a battalion and minutes out of range', function () {
    $admin = User::factory()->admin()->create();
    app(SetPresenceMinimum::class)->handle($admin, $this->battalion->id, 45);
    expect(app(PresenceMinimum::class)->forUnit($this->host->id))->toBe(45);

    expect(fn () => app(SetPresenceMinimum::class)->handle($this->leader, $this->battalion->id, 20))->toThrow(PresenceMinimumForbiddenException::class)
        ->and(fn () => app(SetPresenceMinimum::class)->handle($admin, $this->home->id, 20))->toThrow(PresenceMinimumForbiddenException::class)
        ->and(fn () => app(SetPresenceMinimum::class)->handle($admin, $this->battalion->id, 61))->toThrow(InvalidPresenceMinimumException::class)
        ->and(fn () => app(SetPresenceMinimum::class)->handle($admin, $this->battalion->id, 0))->toThrow(InvalidPresenceMinimumException::class);
});

it('sets the minimum over HTTP on the battalion roster, throttled, and shows it there', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson("/roster/{$this->battalion->id}/presence-minimum", ['minutes' => 35])
        ->assertOk()
        ->assertJsonPath('presenceMinimum.minutes', 35)
        ->assertJsonPath('presenceMinimum.isDefault', false)
        ->assertJsonPath('presenceMinimum.canEdit', true);

    $this->actingAs($this->leader)->postJson("/roster/{$this->battalion->id}/presence-minimum", ['minutes' => 20])->assertForbidden();
    $this->actingAs($admin)->postJson("/roster/{$this->battalion->id}/presence-minimum", ['minutes' => 90])->assertStatus(422);
});

it('names the host practice and the game server on the own roll call row', function () {
    captureHost($this, [seen('76561190000000001', 50)]);

    $row = collect(app(RollcallRoster::class)->forWeek($this->home, $this->week)->troopers)->firstWhere('id', $this->guest->id);

    expect($row['makeupFrom'])->toBe(['section' => '1/2/B/2-7', 'fromServer' => true]);
});

it('backfills past weeks over the slot hour, listing what it would mark on a dry run and writing nothing', function () {
    $previous = $this->week->subWeek();
    $sources = [new CaptureSource('crcon', 'crcon-1', fn ($start, $end) => new CrconPresence(
        $start->format('Y-m-d') === '2026-10-21' ? [seen('76561190000000001', 50)] : [],
        $start, $end, CarbonImmutable::now()->toDateTimeImmutable(),
    ))];
    app()->instance(CaptureSources::class, new CaptureSources([game('hll')->interface->value => $sources]));
    $practices = SectionPractice::query()->count();

    $this->artisan('portal:backfill-practice-captures', ['--dry-run' => true])
        ->expectsOutputToContain('make-up '.$this->guest->fresh()->display_name)
        ->assertSuccessful();

    expect(SectionPractice::query()->count())->toBe($practices)
        ->and(Attendance::query()->exists())->toBeFalse()
        ->and(SyncRun::query()->exists())->toBeFalse()
        ->and(CrossSectionSighting::query()->exists())->toBeFalse();

    $this->artisan('portal:backfill-practice-captures')->assertSuccessful();

    $own = SectionPractice::query()->where('section_id', $this->home->id)->where('week_start', $previous)->sole();
    expect(Attendance::query()->where('section_practice_id', $own->id)->where('trooper_id', $this->guest->id)->value('status'))->toBe(AttendanceStatus::Makeup);
});

it('skips a week whose report was sent when backfilling', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->host->id, 'week_start' => $this->week->subWeek()]);
    app()->instance(CaptureSources::class, new CaptureSources([game('hll')->interface->value => [new CaptureSource('crcon', 'crcon-1', fn ($s, $e) => new CrconPresence([], $s, $e, CarbonImmutable::now()->toDateTimeImmutable()))]]));

    $this->artisan('portal:backfill-practice-captures', ['--dry-run' => true])
        ->expectsOutputToContain('week of 2026-10-17: week locked, its report was sent')
        ->assertSuccessful();
});
