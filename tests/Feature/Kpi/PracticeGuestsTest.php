<?php

use App\Kpi\PracticeGuest;
use App\Kpi\PracticeGuests;
use App\Kpi\Ratio;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\CrossSectionSighting;
use App\Models\Loa;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The Joint Service Achievement Medal evidence (decided 2026-10-23): the guests at a unit's practices over the last
// 12 Cav weeks, with their visits and their own SP % from the one KPI definition; a candidate has at least 3 visits and
// at least 75% own SP. The thresholds are pinned in phpunit.xml (3 / 75 / 12).

const GUESTS_WEEK = '2026-10-03';

function guestsWeek(int $weeksBack = 0): CarbonImmutable
{
    return CarbonImmutable::parse(GUESTS_WEEK.' 00:00:00', 'UTC')->subWeeks($weeksBack);
}

function guestTrooper(Unit $section, TrooperStatus $status = TrooperStatus::Combat, string $username = 'Guest.G'): Trooper
{
    $trooper = Trooper::factory()->create(['status' => $status, 'username' => $username]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/** `$trooper` recorded at `$host`'s practice `$weeksBack` weeks before the current one. */
function guestVisit(Trooper $trooper, Unit $host, int $weeksBack, string $source = CrossSectionSighting::SOURCE_CAPTURE): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($host->id, guestsWeek($weeksBack));
    CrossSectionSighting::query()->create([
        'trooper_id' => $trooper->id,
        'section_practice_id' => $practice->id,
        'week_start' => $practice->week_start,
        'seen_from' => $source === CrossSectionSighting::SOURCE_CAPTURE ? guestsWeek($weeksBack)->addDays(5) : null,
        'seen_until' => $source === CrossSectionSighting::SOURCE_CAPTURE ? guestsWeek($weeksBack)->addDays(5)->addHour() : null,
        'source' => $source,
    ]);
}

/** A leader's mark on `$trooper`'s own section practice `$weeksBack` weeks back (held: a manual SP mark). */
function ownMark(Trooper $trooper, int $weeksBack, AttendanceStatus $status): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($trooper->primary_section_id, guestsWeek($weeksBack));
    Attendance::factory()->create(['trooper_id' => $trooper->id, 'section_practice_id' => $practice->id, 'status' => $status, 'source' => AttendanceSource::Manual]);
}

/** `$attended` present marks then `$held - $attended` missed ones, in the most recent weeks. */
function ownRecord(Trooper $trooper, int $attended, int $held): void
{
    foreach (range(0, $held - 1) as $back) {
        ownMark($trooper, $back, $back < $attended ? AttendanceStatus::Present : AttendanceStatus::Missed);
    }
}

/** @return list<PracticeGuest> */
function guestsOf(Unit $unit): array
{
    return app(PracticeGuests::class)->forUnit($unit, guestsWeek());
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $units = app(UnitRepository::class);

    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->host = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $other = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->cousin = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $other->id);
    $elsewhere = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);
    $this->away = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $elsewhere->id)->id);
});

it('marks a guest with enough visits and own SP as a candidate, on the thresholds\' edges', function (int $visits, int $attended, int $held, bool $candidate) {
    $guest = guestTrooper($this->away);
    foreach (range(1, $visits) as $back) {
        guestVisit($guest, $this->host, $back);
    }
    ownRecord($guest, $attended, $held);

    $row = guestsOf($this->host)[0];

    expect($row->trooper->id)->toBe($guest->id)
        ->and($row->visits)->toBe($visits)
        ->and($row->ownSp)->toEqual(new Ratio($attended, $held))
        ->and($row->candidate)->toBe($candidate);
})->with([
    'three visits, exactly 75%' => [3, 3, 4, true],
    'two visits, all attended' => [2, 4, 4, false],
    'three visits, 66%' => [3, 2, 3, false],
    'many visits, just under 75%' => [5, 8, 11, false],
]);

it('never makes a guest with nothing eligible on their own side a candidate', function () {
    $guest = guestTrooper($this->away);
    foreach ([1, 2, 3] as $back) {
        guestVisit($guest, $this->host, $back);
    }

    $row = guestsOf($this->host)[0];

    expect($row->ownSp)->toEqual(new Ratio(0, 0))->and($row->candidate)->toBeFalse();
});

it('reads the thresholds from config so S1 can change them', function () {
    $guest = guestTrooper($this->away);
    guestVisit($guest, $this->host, 1);
    guestVisit($guest, $this->host, 2);
    ownRecord($guest, 3, 5);

    expect(guestsOf($this->host)[0]->candidate)->toBeFalse();

    config(['portal.jsam.min_visits' => 2, 'portal.jsam.min_own_sp_percent' => 60]);

    expect(guestsOf($this->host)[0]->candidate)->toBeTrue()
        ->and(app(PracticeGuests::class)->criteria()->toArray())->toBe(['minVisits' => 2, 'minOwnSpPercent' => 60, 'windowWeeks' => 12]);
});

it('counts only visits and own marks inside the window of the last 12 Cav weeks, the current one included', function () {
    $guest = guestTrooper($this->away);
    guestVisit($guest, $this->host, 0);
    guestVisit($guest, $this->host, 11);
    guestVisit($guest, $this->host, 12);   // outside
    ownMark($guest, 11, AttendanceStatus::Present);
    ownMark($guest, 12, AttendanceStatus::Missed);   // outside

    $row = guestsOf($this->host)[0];

    expect($row->visits)->toBe(2)
        ->and($row->lastWeek->toDateString())->toBe(GUESTS_WEEK)
        ->and($row->ownSp)->toEqual(new Ratio(1, 1));

    config(['portal.jsam.window_weeks' => 13]);

    expect(guestsOf($this->host)[0]->visits)->toBe(3)
        ->and(guestsOf($this->host)[0]->ownSp)->toEqual(new Ratio(1, 2));
});

it('takes LOA weeks out of the guest\'s own SP through the KPI definition', function () {
    $guest = guestTrooper($this->away);
    foreach ([1, 2, 3] as $back) {
        guestVisit($guest, $this->host, $back);
    }
    ownRecord($guest, 3, 4);
    // A missed practice in a week their leave covers is not held against them (4.4, AD-21).
    ownMark($guest, 5, AttendanceStatus::Missed);
    Loa::factory()->create(['trooper_id' => $guest->id, 'starts_at' => guestsWeek(5)->subDays(2), 'ends_at' => guestsWeek(5)->addDays(9)]);

    $row = guestsOf($this->host)[0];

    expect($row->ownSp)->toEqual(new Ratio(3, 4))->and($row->candidate)->toBeTrue();
});

it('counts an automatic makeup on the guest\'s own rollcall like any attended week', function () {
    $guest = guestTrooper($this->away);
    foreach ([0, 1, 2] as $back) {
        guestVisit($guest, $this->host, $back);
        $practice = app(SectionPracticeRepository::class)->findOrCreate($this->away->id, guestsWeek($back));
        Attendance::factory()->create(['trooper_id' => $guest->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Makeup, 'source' => AttendanceSource::Auto]);
    }
    ownMark($guest, 3, AttendanceStatus::Missed);

    expect(guestsOf($this->host)[0]->ownSp)->toEqual(new Ratio(3, 4))->and(guestsOf($this->host)[0]->candidate)->toBeTrue();
});

it('leaves departed and unplaced troopers out', function () {
    $departed = guestTrooper($this->away, TrooperStatus::Inactive, 'Gone.G');
    $unplaced = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    foreach ([$departed, $unplaced] as $trooper) {
        guestVisit($trooper, $this->host, 1);
    }

    expect(guestsOf($this->host))->toBe([]);
});

it('lists only guests at practices under the viewed unit, from outside it', function () {
    $fromCousin = guestTrooper($this->cousin, username: 'Cousin.C');
    $fromSibling = guestTrooper($this->sibling, username: 'Sibling.S');
    guestVisit($fromCousin, $this->host, 1);
    guestVisit($fromSibling, $this->host, 1);
    $fromAway = guestTrooper($this->away, username: 'Elsewhere.E');
    guestVisit($fromAway, $this->cousin, 1);

    $ids = fn (Unit $unit) => array_map(fn (PracticeGuest $guest) => $guest->trooper->id, guestsOf($unit));

    // The section sees both of its visitors; the platoon only the one from its other platoon; the company neither of
    // those (both are its own) but the one from another company, seen at its other platoon; the sibling nobody.
    expect($ids($this->host))->toEqualCanonicalizing([$fromCousin->id, $fromSibling->id])
        ->and($ids($this->platoon))->toBe([$fromCousin->id])
        ->and($ids($this->company))->toBe([$fromAway->id])
        ->and($ids($this->sibling))->toBe([]);
});

it('counts one visit per host practice, summed over the unit\'s sections', function () {
    $guest = guestTrooper($this->away);
    guestVisit($guest, $this->host, 1);
    guestVisit($guest, $this->sibling, 1, CrossSectionSighting::SOURCE_MANUAL);
    guestVisit($guest, $this->host, 2, CrossSectionSighting::SOURCE_MANUAL);

    expect(guestsOf($this->host)[0]->visits)->toBe(2)
        ->and(guestsOf($this->sibling)[0]->visits)->toBe(1)
        ->and(guestsOf($this->platoon)[0]->visits)->toBe(3);
});

it('sorts candidates first, then by visits', function () {
    $frequent = guestTrooper($this->away, username: 'Frequent.F');
    $candidate = guestTrooper($this->away, username: 'Candidate.C');
    $rare = guestTrooper($this->away, username: 'Rare.R');
    foreach (range(1, 5) as $back) {
        guestVisit($frequent, $this->host, $back);
    }
    foreach (range(1, 3) as $back) {
        guestVisit($candidate, $this->host, $back);
    }
    guestVisit($rare, $this->host, 1);
    ownRecord($candidate, 4, 4);

    expect(array_map(fn (PracticeGuest $guest) => $guest->trooper->username, guestsOf($this->host)))->toBe(['Candidate.C', 'Frequent.F', 'Rare.R']);
});
