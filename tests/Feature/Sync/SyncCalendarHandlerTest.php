<?php

use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Integration\Forum\Requests\ExportCalendarRequest;
use App\Jobs\SyncCalendarJob;
use App\Models\CalendarEvent;
use App\Models\Game;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Sync\SyncCalendarHandler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// #751: the 7Cav calendar sync, against a faked forum export (tests/Fixtures/forum/calendar-export.ics). Nothing here
// reaches the forum: every request is answered by Saloon's mock.

function calExport(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/forum/calendar-export.ics');
}

function calForum(MockResponse $response): MockClient
{
    MockClient::destroyGlobal();

    return MockClient::global([ExportCalendarRequest::class => $response]);
}

/** @return list<string> every stored occurrence as `uid start`, earliest first */
function calStored(): array
{
    return CalendarEvent::query()->orderBy('starts_at')->orderBy('uid')->get()
        ->map(fn (CalendarEvent $event) => $event->uid.' '.$event->starts_at->utc()->format('Y-m-d H:i'))
        ->all();
}

beforeEach(function () {
    // Saturday 14NOV26, the evening after Squad Saturday.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 21:00:00', 'UTC'));
    // No game names a forum calendar unless a test gives it one, so only the whole calendar is read.
    Game::query()->update(['event_calendars' => null]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores when each event takes place from a week before the read, a weekly one once per week, and records the run', function () {
    $mock = calForum(MockResponse::make(calExport(), 200, ['Content-Type' => 'text/calendar; charset=utf-8']));

    app(SyncCalendarHandler::class)->handle();

    $mock->assertSent(ExportCalendarRequest::class);
    expect(calStored())->toBe([
        '1232 2026-11-11 00:00',
        '1064 2026-11-14 19:00',
        '4075 2026-11-15 20:00',
        '3926 2026-11-17 23:00',
        '1232 2026-11-18 00:00',
        '1064 2026-11-21 19:00',
        '4088 2026-11-21 19:30',
        '3926 2026-11-24 23:00',
        '1232 2026-11-25 00:00',
        '1064 2026-11-28 19:00',
    ]);

    $squad = CalendarEvent::query()->where('uid', '1064')->orderBy('starts_at')->first();
    expect($squad->title)->toBe('SQUAD | Squad Saturday @1900Z')
        ->and($squad->ends_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-14 20:00')
        ->and($squad->url)->toBe('https://7cav.us/events/1064/');

    $run = SyncRun::query()->sole();
    expect($run->source)->toBe(SyncRun::SOURCE_CALENDAR)
        ->and($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->inserted)->toBe(10)
        ->and($run->triggered_by)->toBe('system');
});

it('sends no forum key with the export, which is public', function () {
    config()->set('services.sevencav.api_key', 'forum-key');
    app()->forgetInstance(ForumApi::class);
    $mock = calForum(MockResponse::make(calExport(), 200));

    app(SyncCalendarHandler::class)->handle();

    $mock->assertSent(fn ($request) => $request instanceof ExportCalendarRequest && $request->headers()->get('XF-Api-Key') === null);
});

it('deletes what is still to come and was not read again, keeps what took place, and takes a changed title', function () {
    calForum(MockResponse::make(calExport(), 200));
    app(SyncCalendarHandler::class)->handle();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 22:00:00', 'UTC'));
    $only = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:1064\nDTSTART:20230107T190000Z\nDTEND:20230107T200000Z\nSUMMARY:SQUAD | Squad Saturday @ 1900Z\nEND:VEVENT\nEND:VCALENDAR\n";
    calForum(MockResponse::make($only, 200));
    app(SyncCalendarHandler::class)->handle();

    // Trenched n' Tuesday of 11NOV took place before the read, so it stays though the export no longer lists it.
    expect(calStored())->toBe(['1232 2026-11-11 00:00', '1064 2026-11-14 19:00', '1064 2026-11-21 19:00', '1064 2026-11-28 19:00'])
        ->and(CalendarEvent::query()->where('uid', '1064')->pluck('title')->unique()->all())->toBe(['SQUAD | Squad Saturday @ 1900Z']);

    $run = SyncRun::query()->latest('id')->first();
    expect($run->inserted)->toBe(0)->and($run->updated)->toBe(3)->and($run->inactivated)->toBe(6);
});

it('records a failed run and changes nothing when the forum answers with an error', function () {
    calForum(MockResponse::make(calExport(), 200));
    app(SyncCalendarHandler::class)->handle();
    $before = calStored();

    calForum(MockResponse::make('Down for maintenance', 503));

    expect(fn () => app(SyncCalendarHandler::class)->handle())->toThrow(ForumRequestException::class);
    expect(calStored())->toBe($before)
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failure);
});

it('records a failed run when the forum answers with something that is not a calendar', function () {
    calForum(MockResponse::make('<html>Sign in</html>', 200));

    expect(fn () => app(SyncCalendarHandler::class)->handle())->toThrow(ForumRequestException::class, 'did not answer with a calendar');
    expect(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Failure)
        ->and(CalendarEvent::query()->count())->toBe(0);
});

it('deletes nothing on an export with no event at all, and makes the run partial', function () {
    calForum(MockResponse::make(calExport(), 200));
    app(SyncCalendarHandler::class)->handle();
    $before = calStored();

    calForum(MockResponse::make("BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR\n", 200));
    app(SyncCalendarHandler::class)->handle();

    $run = SyncRun::query()->latest('id')->first();
    expect(calStored())->toBe($before)
        ->and($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->inactivated)->toBe(0);
});

it('closes a run left in progress by a killed worker before it starts', function () {
    $orphan = SyncRun::factory()->create(['source' => SyncRun::SOURCE_CALENDAR, 'status' => SyncRunStatus::InProgress, 'finished_at' => null]);
    calForum(MockResponse::make(calExport(), 200));

    app(SyncCalendarHandler::class)->handle();

    expect($orphan->fresh()->status)->toBe(SyncRunStatus::Failure);
});

it('schedules portal:sync-calendar hourly at ten to the hour', function () {
    $this->artisan('list');
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:sync-calendar'));

    expect($event)->not->toBeNull()->and($event->getExpression())->toBe('50 * * * *');
});

it('queues the calendar sync as triggered by system, or runs it in the foreground with --now', function () {
    Queue::fake();
    $this->artisan('portal:sync-calendar')->expectsOutput('Calendar sync queued.')->assertSuccessful();
    Queue::assertPushed(SyncCalendarJob::class, fn (SyncCalendarJob $job) => $job->triggeredBy === 'system');

    Bus::fake();
    $this->artisan('portal:sync-calendar', ['--now' => true])->expectsOutput('Calendar sync finished.')->assertSuccessful();
    Bus::assertDispatchedSync(SyncCalendarJob::class);
});

/** One forum calendar's export, listing the given events as `uid => [title, start]`. */
function calOne(array $events): string
{
    $out = 'BEGIN:VCALENDAR'."\n";
    foreach ($events as $uid => [$title, $start]) {
        $out .= "BEGIN:VEVENT\nUID:{$uid}\nDTSTART:{$start}\nSUMMARY:{$title}\nEND:VEVENT\n";
    }

    return $out."END:VCALENDAR\n";
}

it('reads each forum calendar a game names and stores the calendar of each event, with an event only its calendar lists', function () {
    Game::factory()->create(['name' => 'Squad', 'event_calendars' => ['operation' => [34], 'class' => [], 'ftx' => [35]]]);
    // A retired game's calendars are not read.
    Game::factory()->create(['name' => 'Old game', 'event_calendars' => ['operation' => [99]], 'retired_at' => now()]);

    MockClient::destroyGlobal();
    $mock = MockClient::global([
        '*/calendar/34/export' => MockResponse::make(calOne(['4075' => ['SQUAD | Operation Paper Trail', '20261115T200000Z'], '5000' => ['Night op', '20261116T010000Z']]), 200),
        '*/calendar/35/export' => MockResponse::make(calOne([]), 200),
        '*/events/export' => MockResponse::make(calExport(), 200),
    ]);

    app(SyncCalendarHandler::class)->handle();

    $mock->assertSentCount(3);
    expect(CalendarEvent::query()->where('uid', '4075')->sole()->calendar_id)->toBe(34)
        ->and(CalendarEvent::query()->where('uid', '5000')->sole()->calendar_id)->toBe(34)
        ->and(CalendarEvent::query()->where('uid', '1064')->pluck('calendar_id')->unique()->all())->toBe([null]);
});

it('records a failed run and changes nothing when a forum calendar cannot be read', function () {
    Game::factory()->create(['name' => 'Squad', 'event_calendars' => ['operation' => [34]]]);
    MockClient::destroyGlobal();
    MockClient::global([
        '*/calendar/34/export' => MockResponse::make('Oops', 500),
        '*/events/export' => MockResponse::make(calExport(), 200),
    ]);

    expect(fn () => app(SyncCalendarHandler::class)->handle())->toThrow(ForumRequestException::class);

    expect(CalendarEvent::query()->count())->toBe(0)
        ->and(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Failure);
});
