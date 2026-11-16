<?php

use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Models\CalendarEvent;
use App\Models\Game;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use App\Rollcall\WeekEvents;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// #751: the roll call lists the events of its Cav week on the 7Cav calendar for the section's game, read from the cache
// the calendar sync keeps (never the forum), and the sent report keeps the ones it had.
//
//   1-7 (plays Squad)
//     A/1-7
//       1/A/1-7
//         1/1/A/1-7   the section, which inherits Squad
//   2-7 (no game)
//     B/2-7 / 1/B/2-7 / 1/1/B/2-7

function calEvent(string $uid, string $title, string $start, ?string $url = null): CalendarEvent
{
    return CalendarEvent::query()->create(['uid' => $uid, 'title' => $title, 'starts_at' => CarbonImmutable::parse($start, 'UTC'), 'url' => $url]);
}

function calRead(SyncRunStatus $status = SyncRunStatus::Success, string $at = '2026-11-18 11:50:00'): SyncRun
{
    return SyncRun::factory()->create(['source' => SyncRun::SOURCE_CALENDAR, 'status' => $status, 'started_at' => $at, 'finished_at' => $at]);
}

function calPage(int $sectionId, string $week = '2026-11-14'): Assert
{
    $page = null;
    test()->actingAs(test()->leader)->get("/rollcall/{$sectionId}?week={$week}")
        ->assertOk()
        ->assertInertia(function (Assert $inertia) use (&$page) {
            $page = $inertia;
        });

    return $page;
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));

    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);

    $otherBattalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $otherCompany = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $otherBattalion->id);
    $otherPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $otherCompany->id);
    $this->gameless = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $otherPlatoon->id);

    $this->squad = Game::factory()->create(['name' => 'Squad', 'event_tags' => ['SQUAD |']]);
    $units->setGame($this->battalion, $this->squad->id);

    $this->leader = User::factory()->admin()->create();
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists the events of the week for the game the section inherits, earliest first, each with its name, start and page', function () {
    calRead();
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-14 19:00', 'https://7cav.us/events/1064/');
    calEvent('967', 'squad | Warrior Wednesdays 2300 Zulu', '2026-11-18 23:00');
    calEvent('4075', 'SQUAD | Operation Paper Trail', '2026-11-15 20:00', 'https://7cav.us/events/4075/');
    // Another game's event, and events of the weeks either side: none of them listed.
    calEvent('1232', "[HLL] Operation Trenched n' Tuesday", '2026-11-18 00:00');
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-21 00:00');
    calEvent('1063', 'SQUAD | Squad Friday', '2026-11-13 23:59');

    calPage($this->section->id)
        ->where('events.state', WeekEvents::LISTED)
        ->where('events.game', 'Squad')
        ->where('events.frozen', false)
        ->where('events.lastReadFailed', false)
        ->where('events.readAt', '2026-11-18T11:50:00Z')
        ->where('events.items', [
            ['name' => 'SQUAD | Squad Saturday @1900Z', 'start' => '2026-11-14T19:00:00Z', 'url' => 'https://7cav.us/events/1064/'],
            ['name' => 'SQUAD | Operation Paper Trail', 'start' => '2026-11-15T20:00:00Z', 'url' => 'https://7cav.us/events/4075/'],
            ['name' => 'squad | Warrior Wednesdays 2300 Zulu', 'start' => '2026-11-18T23:00:00Z', 'url' => null],
        ]);
});

it('lists none for a week with no event of the game', function () {
    calRead();
    calEvent('1232', "[HLL] Operation Trenched n' Tuesday", '2026-11-18 00:00');

    calPage($this->section->id)->where('events.state', WeekEvents::LISTED)->where('events.items', []);
});

it('lists none for a section with no game, and says so, whatever is on the calendar', function () {
    calRead();
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-14 19:00');

    calPage($this->gameless->id)->where('events.state', WeekEvents::NO_GAME)->where('events.game', null)->where('events.items', []);
});

it('lists none for a game with no calendar tags', function () {
    calRead();
    $this->squad->update(['event_tags' => null]);
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-14 19:00');

    calPage($this->section->id)->where('events.state', WeekEvents::LISTED)->where('events.items', []);
});

it('says the calendar could not be read when it never has been', function () {
    calRead(SyncRunStatus::Failure);
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-14 19:00');

    calPage($this->section->id)->where('events.state', WeekEvents::UNAVAILABLE)->where('events.items', []);
});

it('keeps listing what was last read when a later read failed, and says so', function () {
    calRead(SyncRunStatus::Success, '2026-11-18 09:50:00');
    calRead(SyncRunStatus::Failure, '2026-11-18 10:50:00');
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-14 19:00');

    calPage($this->section->id)
        ->where('events.state', WeekEvents::LISTED)
        ->where('events.lastReadFailed', true)
        ->where('events.readAt', '2026-11-18T09:50:00Z')
        ->where('events.items.0.name', 'SQUAD | Squad Saturday @1900Z');
});

it('freezes the events on the report when it is sent, so the post keeps them after the calendar changes', function () {
    calRead();
    calEvent('1064', 'SQUAD | Squad Saturday @1900Z', '2026-11-14 19:00');

    confirmPracticeHeld($this->section, '2026-11-14');
    app(SendWeeklyReportHandler::class)->handle($this->leader, new SendWeeklyReport($this->section->id, CarbonImmutable::parse('2026-11-14', 'UTC'), 'Weekly report', 'All good.'));

    $kept = WeeklyReport::query()->where('unit_id', $this->section->id)->sole()->calendar_events;
    expect($kept['state'])->toBe(WeekEvents::LISTED)
        ->and($kept['items'])->toBeIgnoringKeyOrder([['name' => 'SQUAD | Squad Saturday @1900Z', 'start' => '2026-11-14T19:00:00Z', 'url' => null]]);

    // The calendar changes after the send: the event is renamed and another is added.
    CalendarEvent::query()->update(['title' => 'SQUAD | Squad Saturday (renamed)']);
    calEvent('4075', 'SQUAD | Operation Paper Trail', '2026-11-15 20:00');

    calPage($this->section->id)
        ->where('events.frozen', true)
        ->where('events.items', [['name' => 'SQUAD | Squad Saturday @1900Z', 'start' => '2026-11-14T19:00:00Z', 'url' => null]]);
});

it('keeps no events on the report of a platoon, which has no roll call', function () {
    $platoon = $this->section->parent;

    confirmPracticeHeld($this->section, '2026-11-14');
    app(SendWeeklyReportHandler::class)->handle($this->leader, new SendWeeklyReport($this->section->id, CarbonImmutable::parse('2026-11-14', 'UTC'), 'Weekly report', 'All good.'));
    app(SendWeeklyReportHandler::class)->handle($this->leader, new SendWeeklyReport($platoon->id, CarbonImmutable::parse('2026-11-14', 'UTC'), 'Weekly report', 'All good.'));

    expect(WeeklyReport::query()->where('unit_id', $platoon->id)->sole()->calendar_events)->toBeNull();
});

it('matches a game to an event by any of its tags anywhere in the title, ignoring case', function () {
    $game = new Game(['event_tags' => ['[HLL]', ' Hell Let Loose ', '']]);

    expect($game->marksEvent("[HLL] Operation Trenched n' Tuesday"))->toBeTrue()
        ->and($game->marksEvent('[hll] [SOI] Basic Infantry Combat Training'))->toBeTrue()
        ->and($game->marksEvent('Campaign | hell let loose | night op'))->toBeTrue()
        ->and($game->marksEvent('[HLLV] Operation Tunnel Trap Tuesday'))->toBeFalse()
        ->and($game->marksEvent('SQUAD | Squad Saturday'))->toBeFalse()
        ->and((new Game(['event_tags' => null]))->marksEvent('[HLL] Anything'))->toBeFalse();
});

it('gives the games the migrations create the tags the 7Cav calendar marks their events with', function () {
    expect(game('hll')->eventTags())->toBe(['[HLL]'])
        ->and(game('hll-vietnam')->eventTags())->toBe(['[HLLV]', '[HLL:Vietnam]'])
        ->and(game('arma')->eventTags())->toBe(['Arma Reforger'])
        ->and(game('wardogs')->eventTags())->toBe(['Wardogs']);
});
