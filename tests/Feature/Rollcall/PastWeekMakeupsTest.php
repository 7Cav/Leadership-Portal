<?php

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\GameIdentities\LinkUnmatchedPlayer;
use App\Commands\GameIdentities\LinkUnmatchedPlayerHandler;
use App\Commands\GameIdentities\UnmatchedPlayerResolutionException;
use App\Commands\Guests\GuestAttendanceRefusedException;
use App\Commands\Guests\RecordGuestAttendance;
use App\Commands\Guests\RecordGuestAttendanceHandler;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The 2026-10-29 audit (M2): a guest's makeup belongs on their own section's rollcall for the host's week. For a week
// that has ended that is the section whose rollcall lists them that week, read from the placement history, not the one
// they were moved to since. The running week keeps reading where they are placed now. The case is the closing week:
// on Saturday 10Oct26 the sync moves the guest from A to B and the host's own trooper from H to B, while the 03Oct26
// reports are still due.

function pastMakeupsAt(string $moment): void
{
    Carbon::setTestNow(CarbonImmutable::parse($moment, 'UTC'));
}

function pastMakeupsGuest(object $test, Trooper $trooper, CarbonImmutable $week): string
{
    return app(RecordGuestAttendanceHandler::class)->handle($test->hostLeader, new RecordGuestAttendance($test->h->id, $week, $trooper->id))->makeup;
}

function pastMakeupsMark(Unit $section, CarbonImmutable $week, Trooper $trooper): ?Attendance
{
    $practice = SectionPractice::query()->where('section_id', $section->id)->where('week_start', $week)->first();

    return $practice === null ? null : Attendance::query()->where(['section_practice_id' => $practice->id, 'trooper_id' => $trooper->id])->first();
}

function pastMakeupsPlayer(Unit $section, CarbonImmutable $week, Trooper $trooper): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, $week);
    PracticeCapturePlayer::query()->create([
        'section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => 'Unknown', 'platform_id' => sprintf('76561190%09d', $trooper->id),
    ]);
}

function pastMakeupsLink(object $test, Unit $section, CarbonImmutable $week, Trooper $trooper)
{
    return app(LinkUnmatchedPlayerHandler::class)->handle($test->co, new LinkUnmatchedPlayer(
        $section->id, $week->toDateTimeImmutable(), game('hll'), sprintf('76561190%09d', $trooper->id), $trooper->id,
    ));
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $reg = $units->create(UnitType::Regiment, 'Reg', null, null);
    $bn = $units->create(UnitType::Battalion, 'Bn', '1-7', $reg->id);
    $company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $bn->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->h = $units->create(UnitType::Section, 'H', '1/1/A/1-7', $platoon->id);
    $this->a = $units->create(UnitType::Section, 'A', '2/1/A/1-7', $platoon->id);
    $this->b = $units->create(UnitType::Section, 'B', '3/1/A/1-7', $platoon->id);
    $this->hostLeader = User::factory()->commanding($this->h)->create();
    $this->co = User::factory()->commanding($company)->create();

    // Placed by the sync before the week of 03Oct26: the guest in A, the host's own trooper in H.
    pastMakeupsAt('2026-09-30 12:00:00');
    $this->guest = Trooper::factory()->create();
    $this->hostOwn = Trooper::factory()->create();
    $this->troopers = app(TrooperRepository::class);
    $this->troopers->updatePrimarySections([$this->guest->cav_id => $this->a->id, $this->hostOwn->cav_id => $this->h->id]);
    $this->week = CarbonImmutable::parse('2026-10-03', 'UTC');
    $this->running = $this->week->addWeek();

    // Saturday 10Oct26: both move to B, while the 03Oct26 reports are still due.
    pastMakeupsAt('2026-10-10 08:00:00');
    $this->troopers->updatePrimarySections([$this->guest->cav_id => $this->b->id, $this->hostOwn->cav_id => $this->b->id]);
    pastMakeupsAt('2026-10-10 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('puts a closing-week guest\'s makeup on the rollcall that lists them that week, not the section they moved to', function () {
    expect(pastMakeupsGuest($this, $this->guest, $this->week))->toBe('marked');

    $mark = pastMakeupsMark($this->a, $this->week, $this->guest);
    $host = app(SectionPracticeRepository::class)->find($this->h->id, $this->week);
    expect($mark?->status)->toBe(AttendanceStatus::Makeup)
        ->and($mark?->source)->toBe(AttendanceSource::Auto)
        ->and(pastMakeupsMark($this->b, $this->week, $this->guest))->toBeNull()
        ->and($this->troopers->onRollcall($this->a, $this->week)->troopers->pluck('id')->all())->toContain($this->guest->id)
        ->and(app(MarkAttendanceHandler::class)->makeupsMissing($host, [$this->guest->id]))->toBe([]);
});

it('locks a closing-week guest\'s makeup by their own rollcall that week, and offers no retry while it is confirmed', function () {
    $practices = app(SectionPracticeRepository::class);
    $practices->confirm($practices->findOrCreate($this->a->id, $this->week), $this->hostLeader->id);

    expect(pastMakeupsGuest($this, $this->guest, $this->week))->toBe('locked')
        ->and(pastMakeupsMark($this->a, $this->week, $this->guest))->toBeNull()
        ->and(pastMakeupsMark($this->b, $this->week, $this->guest))->toBeNull()
        ->and(app(MarkAttendanceHandler::class)->makeupsMissing($practices->find($this->h->id, $this->week), [$this->guest->id]))->toBe([]);
});

it('puts a running-week guest\'s makeup on the section they are placed in now', function () {
    expect(pastMakeupsGuest($this, $this->guest, $this->running))->toBe('marked')
        ->and(pastMakeupsMark($this->b, $this->running, $this->guest)?->status)->toBe(AttendanceStatus::Makeup)
        ->and(pastMakeupsMark($this->a, $this->running, $this->guest))->toBeNull();
});

it('refuses the host\'s own trooper of the closing week as a guest, and takes them as one for the running week', function () {
    expect(fn () => pastMakeupsGuest($this, $this->hostOwn, $this->week))
        ->toThrow(fn (GuestAttendanceRefusedException $e) => expect($e->reason)->toBe('own_section'));

    expect(pastMakeupsGuest($this, $this->hostOwn, $this->running))->toBe('marked')
        ->and(pastMakeupsMark($this->b, $this->running, $this->hostOwn)?->status)->toBe(AttendanceStatus::Makeup);
});

it('links an unmatched player on the closing week only to a trooper on that week\'s rollcall, and on the running week to who is placed now', function () {
    pastMakeupsPlayer($this->a, $this->week, $this->guest);
    pastMakeupsPlayer($this->b, $this->week, $this->guest);
    pastMakeupsPlayer($this->b, $this->running, $this->guest);

    expect(fn () => pastMakeupsLink($this, $this->b, $this->week, $this->guest))
        ->toThrow(UnmatchedPlayerResolutionException::class, 'Choose a trooper from this section');

    expect(pastMakeupsLink($this, $this->a, $this->week, $this->guest)->marked)->toBe(1)
        ->and(pastMakeupsMark($this->a, $this->week, $this->guest)?->status)->toBe(AttendanceStatus::Present)
        ->and(pastMakeupsLink($this, $this->b, $this->running, $this->guest)->marked)->toBe(1);
});
