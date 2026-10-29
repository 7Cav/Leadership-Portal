<?php

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

// JSAM evidence (decided 2026-10-23): a host section's leader adds a trooper from another section who attended their
// practice, finds them by username outside their own scope, and can undo an entry they added. The makeup it brings is
// covered in Commands/Guests/GuestMakeupTest.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $units = app(UnitRepository::class);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', null);
    $this->host = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->home = $units->create(UnitType::Section, '3rd Section', '3/2/B/1-7', $units->create(UnitType::Platoon, '2nd Platoon', '2/B/1-7', null)->id);
    $this->leader = User::factory()->commanding($this->host)->create(['username' => 'Host.L']);
    $this->guest = guestOf($this->home, ['username' => 'Visitor.V', 'rank_short' => 'PFC']);
});

function guestOf(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function addGuest(User $user, int $unitId, int $trooperId, string $week = '2026-10-03')
{
    return test()->actingAs($user)->postJson("/rollcall/{$unitId}/guests", ['week' => $week, 'trooper_id' => $trooperId]);
}

function removeGuest(User $user, int $unitId, int $trooperId, string $week = '2026-10-03')
{
    return test()->actingAs($user)->postJson("/rollcall/{$unitId}/guests/remove", ['week' => $week, 'trooper_id' => $trooperId]);
}

it('records a hand-added guest at the host practice, audited with the leader as the actor', function () {
    addGuest($this->leader, $this->host->id, $this->guest->id)
        ->assertOk()
        ->assertExactJson([
            'guest' => [
                'trooperId' => $this->guest->id, 'name' => 'Visitor.V', 'displayName' => $this->guest->display_name, 'rank' => 'PFC',
                'section' => '3/2/B/1-7', 'source' => 'manual', 'recordedBy' => 'Host.L', 'makeupMissing' => false,
            ],
            'makeup' => 'marked',
        ]);

    $practice = SectionPractice::query()->where('section_id', $this->host->id)->sole();
    $row = CrossSectionSighting::query()->sole();
    $entry = AuditLogEntry::query()->where('action', 'guest.recorded')->sole();
    expect($row->source)->toBe('manual')
        ->and($row->section_practice_id)->toBe($practice->id)
        ->and($row->recorded_by)->toBe($this->leader->id)
        ->and($row->week_start->equalTo($this->week))->toBeTrue()
        ->and($entry->actor_id)->toBe($this->leader->id)
        ->and($entry->target_type)->toBe('SectionPractice')
        ->and((int) $entry->target_id)->toBe($practice->id);
});

it('requires authentication for the guest search and writes', function () {
    $this->getJson("/rollcall/{$this->host->id}/guests/search?q=visitor")->assertUnauthorized();
    $this->postJson("/rollcall/{$this->host->id}/guests", ['week' => '2026-10-03', 'trooper_id' => $this->guest->id])->assertUnauthorized();

    expect(CrossSectionSighting::query()->count())->toBe(0);
});

it('refuses a section outside the leader\'s write scope, and a staff grant that only reads it, writing nothing', function () {
    $other = User::factory()->commanding($this->home)->create();
    $granted = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->host->id]);

    addGuest($other, $this->host->id, $this->guest->id)->assertForbidden();
    addGuest($granted, $this->host->id, $this->guest->id)->assertForbidden();
    removeGuest($granted, $this->host->id, $this->guest->id)->assertForbidden();

    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and(SectionPractice::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses every guest write during a view-as preview', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->host->id]);

    addGuest($admin, $this->host->id, $this->guest->id)->assertForbidden();

    expect(CrossSectionSighting::query()->count())->toBe(0);
});

it('refuses a trooper who cannot be a guest here, saying why', function (Closure $trooper, string $reason) {
    addGuest($this->leader, $this->host->id, $trooper($this)->id)
        ->assertUnprocessable()
        ->assertJsonPath('reason', $reason);

    expect(CrossSectionSighting::query()->count())->toBe(0)->and(SectionPractice::query()->count())->toBe(0);
})->with([
    'in the host section' => [fn ($t) => guestOf($t->host), 'own_section'],
    'departed' => [fn ($t) => guestOf($t->home, ['status' => TrooperStatus::Inactive]), 'not_serving'],
    'unplaced' => [fn () => Trooper::factory()->create(['status' => TrooperStatus::Combat]), 'not_placed'],
    'unknown' => [fn () => (object) ['id' => 999999999], 'unknown_trooper'],
]);

it('refuses a trooper already recorded at this practice, by the server or by hand', function () {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->host->id, $this->week);
    CrossSectionSighting::query()->create([
        'trooper_id' => $this->guest->id, 'section_practice_id' => $practice->id, 'week_start' => $this->week,
        'seen_from' => '2026-10-08 18:00:00', 'seen_until' => '2026-10-08 19:30:00', 'source' => 'capture',
    ]);

    addGuest($this->leader, $this->host->id, $this->guest->id)->assertUnprocessable()->assertJsonPath('reason', 'already_listed');

    expect(CrossSectionSighting::query()->sole()->source)->toBe('capture');
});

it('answers a guest already added by hand with the guest again and their makeup, not a refusal', function () {
    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk();

    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk()
        ->assertJsonPath('guest.trooperId', $this->guest->id)
        ->assertJsonPath('makeup', 'marked');

    expect(CrossSectionSighting::query()->count())->toBe(1);
});

it('refuses a week the host may no longer change', function () {
    // A confirmed host practice (7.8).
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->host->id, $this->week);
    app(SectionPracticeRepository::class)->confirm($practice, $this->leader->id);
    addGuest($this->leader, $this->host->id, $this->guest->id)->assertUnprocessable()->assertJsonPath('reason', 'confirmed');

    // A week before the floor (20 weeks, phpunit.xml).
    addGuest($this->leader, $this->host->id, $this->guest->id, '2026-04-04')->assertUnprocessable()->assertJsonPath('reason', 'before_floor');

    // A week whose host report was sent (AD-12).
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->host->id, 'week_start' => $this->week->subWeek()]);
    addGuest($this->leader, $this->host->id, $this->guest->id, '2026-09-26')->assertStatus(409)->assertJsonPath('locked', true);

    // A future week has no practice yet.
    addGuest($this->leader, $this->host->id, $this->guest->id, '2026-10-10')->assertUnprocessable();

    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'guest.recorded')->count())->toBe(0);
});

it('refuses a unit that is not a section', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();

    addGuest($platoonLeader, $this->platoon->id, $this->guest->id)->assertUnprocessable()->assertJsonPath('reason', 'not_a_section');
});

it('removes a hand-added guest, audited, and answers what became of the makeup', function () {
    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk();

    removeGuest($this->leader, $this->host->id, $this->guest->id)
        ->assertOk()
        ->assertExactJson(['removed' => true, 'makeup' => 'withdrawn']);

    $entry = AuditLogEntry::query()->where('action', 'guest.removed')->sole();
    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and($entry->actor_id)->toBe($this->leader->id)
        ->and($entry->target_type)->toBe('SectionPractice');
});

it('never removes what the game server saw, and says when there is nothing to remove', function () {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->host->id, $this->week);
    CrossSectionSighting::query()->create([
        'trooper_id' => $this->guest->id, 'section_practice_id' => $practice->id, 'week_start' => $this->week,
        'seen_from' => '2026-10-08 18:00:00', 'seen_until' => '2026-10-08 19:30:00', 'source' => 'capture',
    ]);

    removeGuest($this->leader, $this->host->id, $this->guest->id)->assertUnprocessable()->assertJsonPath('reason', 'captured');
    removeGuest($this->leader, $this->host->id, guestOf($this->home)->id)->assertUnprocessable()->assertJsonPath('reason', 'not_listed');

    expect(CrossSectionSighting::query()->count())->toBe(1);
});

it('refuses an undo in a host week that has since been confirmed or locked, keeping the entry', function () {
    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk();
    $practice = SectionPractice::query()->where('section_id', $this->host->id)->sole();
    app(SectionPracticeRepository::class)->confirm($practice, $this->leader->id);

    removeGuest($this->leader, $this->host->id, $this->guest->id)->assertUnprocessable()->assertJsonPath('reason', 'confirmed');

    WeeklyReport::factory()->sent()->create(['unit_id' => $this->host->id, 'week_start' => $this->week]);
    app(SectionPracticeRepository::class)->unconfirm($practice);

    removeGuest($this->leader, $this->host->id, $this->guest->id)->assertStatus(409)->assertJsonPath('locked', true);

    expect(CrossSectionSighting::query()->count())->toBe(1);
});

it('lists the practice\'s guests on the rollcall, capture and manual, with their source', function () {
    $seen = guestOf($this->home, ['username' => 'Seen.S']);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->host->id, $this->week);
    CrossSectionSighting::query()->create([
        'trooper_id' => $seen->id, 'section_practice_id' => $practice->id, 'week_start' => $this->week,
        'seen_from' => '2026-10-08 18:00:00', 'seen_until' => '2026-10-08 19:30:00', 'source' => 'capture',
    ]);
    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk();

    $this->actingAs($this->leader)->get("/rollcall/{$this->host->id}")
        ->assertInertia(fn ($page) => $page
            ->has('guests', 2)
            ->where('guests.0.name', 'Seen.S')
            ->where('guests.0.source', 'capture')
            ->where('guests.0.recordedBy', null)
            ->where('guests.1.name', 'Visitor.V')
            ->where('guests.1.source', 'manual')
            ->where('guests.1.recordedBy', 'Host.L')
            ->where('guests.1.section', '3/2/B/1-7'));
});

it('finds serving troopers from other sections by username, outside the leader\'s scope, with only what the picker shows', function () {
    guestOf($this->host, ['username' => 'Visitor.Host']);
    guestOf($this->home, ['username' => 'Visitor.Gone', 'status' => TrooperStatus::Inactive]);
    Trooper::factory()->create(['username' => 'Visitor.Nowhere', 'status' => TrooperStatus::Combat]);

    $this->actingAs($this->leader)->getJson("/rollcall/{$this->host->id}/guests/search?q=visitor")
        ->assertOk()
        ->assertExactJson([
            'troopers' => [['id' => $this->guest->id, 'name' => 'Visitor.V', 'rank' => 'PFC', 'section' => '3/2/B/1-7']],
            'more' => false,
        ]);
});

it('offers the guest search only to someone who may add guests here, and only for a real search', function () {
    $granted = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->host->id]);

    $this->actingAs($granted)->getJson("/rollcall/{$this->host->id}/guests/search?q=visitor")->assertForbidden();
    $this->actingAs(User::factory()->commanding($this->home)->create())->getJson("/rollcall/{$this->host->id}/guests/search?q=visitor")->assertForbidden();
    $this->actingAs($this->leader)->getJson("/rollcall/{$this->host->id}/guests/search?q=v")->assertUnprocessable();

    expect(Route::getRoutes()->getByName('rollcall.guests.search')->gatherMiddleware())->toContain('throttle:trooper-search');
});

it('keeps the guest on the host rollcall only: no row on the host practice for them', function () {
    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk();

    $practice = SectionPractice::query()->where('section_id', $this->host->id)->sole();
    expect(Attendance::query()->where('section_practice_id', $practice->id)->count())->toBe(0)
        ->and(Attendance::query()->where('trooper_id', $this->guest->id)->sole()->status)->toBe(AttendanceStatus::Makeup)
        ->and(Attendance::query()->where('trooper_id', $this->guest->id)->sole()->source)->toBe(AttendanceSource::Auto);
});

// After a reload the picker shows a hand-added guest as already listed, so a guest whose makeup step failed after the
// entry was saved is marked on the list, and the leader retries from the row through the same audited path.
function failGuestMakeup(User $leader, int $hostId, int $trooperId): void
{
    $failing = Mockery::mock(app(MarkAttendanceHandler::class))->makePartial();
    $failing->shouldReceive('preMarkMakeups')->once()->andThrow(new RuntimeException('connection lost'));
    app()->instance(MarkAttendanceHandler::class, $failing);

    addGuest($leader, $hostId, $trooperId)->assertServerError();

    app()->forgetInstance(MarkAttendanceHandler::class);
}

it('marks a hand-added guest whose makeup is missing on the rollcall, and only that one', function () {
    $seen = guestOf($this->home, ['username' => 'Seen.S']);
    $fine = guestOf($this->home, ['username' => 'Fine.F']);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->host->id, $this->week);
    CrossSectionSighting::query()->create([
        'trooper_id' => $seen->id, 'section_practice_id' => $practice->id, 'week_start' => $this->week,
        'seen_from' => '2026-10-08 18:00:00', 'seen_until' => '2026-10-08 19:30:00', 'source' => 'capture',
    ]);
    addGuest($this->leader, $this->host->id, $fine->id)->assertOk();
    failGuestMakeup($this->leader, $this->host->id, $this->guest->id);

    $this->actingAs($this->leader)->get("/rollcall/{$this->host->id}")
        ->assertInertia(fn ($page) => $page
            ->has('guests', 3)
            ->where('guests.0.name', 'Fine.F')
            ->where('guests.0.makeupMissing', false)
            ->where('guests.1.name', 'Seen.S')
            ->where('guests.1.makeupMissing', false)
            ->where('guests.2.name', 'Visitor.V')
            ->where('guests.2.makeupMissing', true));
});

it('retries the missing makeup from the row, audited, and stops offering it once the makeup is in', function () {
    failGuestMakeup($this->leader, $this->host->id, $this->guest->id);

    addGuest($this->leader, $this->host->id, $this->guest->id)->assertOk()
        ->assertJsonPath('guest.makeupMissing', false)
        ->assertJsonPath('makeup', 'marked');

    $home = SectionPractice::query()->where('section_id', $this->home->id)->sole();
    expect(Attendance::query()->where('section_practice_id', $home->id)->where('trooper_id', $this->guest->id)->sole()->status)->toBe(AttendanceStatus::Makeup)
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_marked')->where('actor_id', $this->leader->id)->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'guest.recorded')->count())->toBe(1)
        ->and(CrossSectionSighting::query()->count())->toBe(1);

    $this->actingAs($this->leader)->get("/rollcall/{$this->host->id}")
        ->assertInertia(fn ($page) => $page->where('guests.0.makeupMissing', false));
});

it('offers no retry when a retry could not help or the makeup is already somewhere', function (Closure $arrange) {
    failGuestMakeup($this->leader, $this->host->id, $this->guest->id);
    $arrange($this);

    $this->actingAs($this->leader)->get("/rollcall/{$this->host->id}")
        ->assertInertia(fn ($page) => $page->where('guests.0.makeupMissing', false));
})->with([
    'their own week was sent' => [fn ($test) => WeeklyReport::factory()->sent()->create(['unit_id' => $test->home->id, 'week_start' => $test->week])],
    'their own leader marked them by hand' => [fn ($test) => Attendance::query()->create([
        'trooper_id' => $test->guest->id, 'section_practice_id' => app(SectionPracticeRepository::class)->findOrCreate($test->home->id, $test->week)->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual,
    ])],
    'they no longer serve' => [fn ($test) => $test->guest->forceFill(['status' => TrooperStatus::Inactive])->save()],
    'the makeup is on a section they have since left' => [function ($test) {
        $old = SectionPractice::query()->create(['section_id' => $test->home->id, 'week_start' => $test->week]);
        Attendance::query()->create(['trooper_id' => $test->guest->id, 'section_practice_id' => $old->id, 'status' => AttendanceStatus::Makeup, 'source' => AttendanceSource::Auto]);
        $test->guest->forceFill(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id])->save();
    }],
]);
