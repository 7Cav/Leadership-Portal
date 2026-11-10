<?php

use App\Integration\Cav\CavApi;
use App\Loa\LoaCoverage;
use App\Loa\LoaCoverageState;
use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Sync\SyncLoasHandler;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * #690: the LOA half of the leave sync, end to end against a faked roster API. The forum half is not configured in
 * these tests (no forum key), so it is skipped; SyncLoasHandlerTest covers it.
 */
const ROSTER_LOAS_URL = 'api.7cav.us/api/v1/loas*';

function rosterApiLoa(array $overrides = []): array
{
    return [
        'threadId' => '101150',
        'forumUrl' => 'https://7cav.us/threads/101150/',
        'postDate' => CarbonImmutable::parse('2026-11-01 09:00', 'UTC')->getTimestamp(),
        'forum' => 'LOA_FORUM_1_7',
        'subject' => ['userId' => '4453', 'username' => 'Colt.DL', 'milpacId' => '2246'],
        'startDate' => '2026-11-02',
        'endDate' => '2026-11-09',
        'state' => 'LOA_STATE_OPEN',
        'reviewReason' => 'LOA_REVIEW_REASON_UNSPECIFIED',
        'reason' => 'Work trip.',
        ...$overrides,
    ];
}

/** Fake the roster API's list. `$queries` collects what each call asked for. */
function rosterApiReturns(array|MockResponse $loas, ?array &$queries = null): MockClient
{
    MockClient::destroyGlobal();
    $queries = [];
    $sink = &$queries;

    return MockClient::global([ROSTER_LOAS_URL => function (PendingRequest $request) use ($loas, &$sink) {
        $sink[] = $request->query()->all();

        return $loas instanceof MockResponse ? $loas : MockResponse::make(['loas' => $loas]);
    }]);
}

function rosterSyncRun(bool $full = false): void
{
    config(['services.cav.api_key' => 'cav7_test-key']);
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));

    app(SyncLoasHandler::class)->handle('system', $full);
}

function latestRosterLoaRun(): SyncRun
{
    return SyncRun::where('source', 'loa')->orderByDesc('id')->firstOrFail();
}

function colt(array $attributes = []): Trooper
{
    return Trooper::factory()->create(['username' => 'Colt.DL', 'forum_user_id' => 4453, 'cav_id' => 2246, ...$attributes]);
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC')));

describe('storing what the roster API lists', function () {
    it('stores an LOA for the trooper the API names, keyed by its thread', function () {
        $trooper = colt();
        rosterApiReturns([rosterApiLoa()]);

        rosterSyncRun();

        $row = Loa::sole();
        expect($row->forum_thread_id)->toBe(101150)
            ->and($row->trooper_id)->toBe($trooper->id)
            ->and($row->type)->toBe(LoaType::Loa)
            ->and($row->parse_status)->toBe(LoaParseStatus::Ok)
            ->and($row->starts_at->toDateString())->toBe('2026-11-02')
            ->and($row->ends_at->toDateString())->toBe('2026-11-09')
            ->and($row->forum)->toBe('1-7')
            ->and($row->forum_node_id)->toBeNull()
            ->and($row->source_thread)->toBe('https://7cav.us/threads/101150/')
            ->and($row->posted_at->toDateTimeString())->toBe('2026-11-01 09:00:00')
            ->and($row->raw_username)->toBe('Colt.DL')
            ->and($row->raw_excerpt)->toBe('Work trip.')
            ->and(latestRosterLoaRun()->status)->toBe(SyncRunStatus::Success)
            ->and(latestRosterLoaRun()->inserted)->toBe(1)
            ->and(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-11-07', 'UTC'))->state)->toBe(LoaCoverageState::Covered);
    });

    it('stores an upcoming and an ended LOA as clean leave too', function (string $state) {
        colt();
        rosterApiReturns([rosterApiLoa(['state' => $state])]);

        rosterSyncRun();

        expect(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok);
    })->with(['LOA_STATE_UPCOMING', 'LOA_STATE_ENDED']);

    it('matches on the milpac id when no trooper carries the forum user id', function () {
        $trooper = colt(['forum_user_id' => null]);
        rosterApiReturns([rosterApiLoa()]);

        rosterSyncRun();

        expect(Loa::sole()->trooper_id)->toBe($trooper->id)->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok);
    });

    it('prefers the forum user id over the milpac id', function () {
        $byForum = colt(['cav_id' => 1]);
        Trooper::factory()->create(['username' => 'Other.A', 'forum_user_id' => 9, 'cav_id' => 2246]);
        rosterApiReturns([rosterApiLoa()]);

        rosterSyncRun();

        expect(Loa::sole()->trooper_id)->toBe($byForum->id);
    });

    it('sends a thread the API flagged to review, with its reason, no leave asserted, and the dates apart', function () {
        $trooper = colt();
        rosterApiReturns([rosterApiLoa(['state' => 'LOA_STATE_NEEDS_REVIEW', 'reviewReason' => 'LOA_REVIEW_REASON_TOO_LONG', 'endDate' => '2026-12-30'])]);

        rosterSyncRun();

        $row = Loa::sole();
        expect($row->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($row->review_reason)->toBe('The leave is longer than 14 days.')
            ->and($row->trooper_id)->toBe($trooper->id)
            ->and($row->starts_at)->toBeNull()
            ->and($row->ends_at)->toBeNull()
            ->and($row->review_starts_at->toDateString())->toBe('2026-11-02')
            ->and($row->review_ends_at->toDateString())->toBe('2026-12-30')
            ->and(latestRosterLoaRun()->needs_review)->toBe(1)
            ->and(latestRosterLoaRun()->status)->toBe(SyncRunStatus::Success);
    });

    it('sends a thread whose member the API did not find to review, with nobody excused', function () {
        rosterApiReturns([rosterApiLoa([
            'subject' => ['userId' => '0', 'username' => 'Nobody.X', 'milpacId' => '0'],
            'startDate' => '', 'endDate' => '', 'state' => 'LOA_STATE_NEEDS_REVIEW', 'reviewReason' => 'LOA_REVIEW_REASON_UNKNOWN_SUBJECT',
        ])]);

        rosterSyncRun();

        $row = Loa::sole();
        expect($row->trooper_id)->toBeNull()
            ->and($row->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($row->raw_username)->toBe('Nobody.X')
            ->and($row->review_starts_at)->toBeNull();
    });

    it('sends a clean LOA for a member the portal does not know to review, rather than guessing', function () {
        rosterApiReturns([rosterApiLoa()]);

        rosterSyncRun();

        expect(Loa::sole()->trooper_id)->toBeNull()
            ->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and(Loa::sole()->review_reason)->toBe('No trooper on the portal matches this member.');
    });

    it('does not guess between two troopers that carry the same id', function () {
        Trooper::factory()->count(2)->create(['forum_user_id' => 4453]);
        rosterApiReturns([rosterApiLoa(['subject' => ['userId' => '4453', 'username' => 'Colt.DL', 'milpacId' => '0']])]);

        rosterSyncRun();

        expect(Loa::sole()->trooper_id)->toBeNull()->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::NeedsReview);
    });

    it('updates the row an earlier forum read stored for the same thread, instead of adding a second', function () {
        $trooper = colt();
        $old = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 101150, 'forum_node_id' => 180, 'starts_at' => '2026-11-02', 'ends_at' => '2026-11-04']);
        rosterApiReturns([rosterApiLoa(['endDate' => '2026-11-09'])]);

        rosterSyncRun();

        expect(Loa::count())->toBe(1)
            ->and($old->fresh()->ends_at->toDateString())->toBe('2026-11-09')
            ->and($old->fresh()->forum_node_id)->toBeNull()
            ->and(latestRosterLoaRun()->updated)->toBe(1);
    });

    it('does nothing to the rows when nothing changed', function () {
        colt();
        rosterApiReturns([rosterApiLoa()]);
        rosterSyncRun();

        rosterSyncRun();

        expect(Loa::count())->toBe(1)->and(latestRosterLoaRun()->inserted)->toBe(0)->and(latestRosterLoaRun()->updated)->toBe(0);
    });

    it('builds the thread address when the API gave none', function () {
        colt();
        rosterApiReturns([rosterApiLoa(['forumUrl' => ''])]);

        rosterSyncRun();

        expect(Loa::sole()->source_thread)->toBe('https://7cav.us/threads/101150/');
    });
});

describe('how far back it asks', function () {
    it('asks for the whole year on the first run, a day short of the API limit, whatever the kind of run', function () {
        rosterApiReturns([], $queries);

        rosterSyncRun();

        expect($queries)->toBe([['endedSince' => '2025-11-11']]);
    });

    it('asks for the last two weeks on an hourly run once it has synced before', function () {
        rosterApiReturns([], $queries);
        rosterSyncRun();

        rosterSyncRun();

        expect($queries[1])->toBe(['endedSince' => '2026-10-27']);
    });

    it('asks for the whole year on a full run', function () {
        rosterApiReturns([], $queries);
        rosterSyncRun();

        rosterSyncRun(full: true);

        expect($queries[1])->toBe(['endedSince' => '2025-11-11']);
    });
});

describe('a thread that was deleted', function () {
    /** A trooper's LOA, stored by a first run; then the thread is deleted from the forum. */
    function loaStoredThenDeleted(): Trooper
    {
        $trooper = colt();
        rosterApiReturns([rosterApiLoa()]);
        rosterSyncRun();
        test()->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00', 'UTC'));

        return $trooper;
    }

    it('is deleted from the portal by the next full run, and the trooper is no longer covered', function () {
        $trooper = loaStoredThenDeleted();
        expect(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-11-07', 'UTC'))->state)->toBe(LoaCoverageState::Covered);

        rosterApiReturns([]);
        rosterSyncRun(full: true);

        expect(Loa::count())->toBe(0)
            ->and(latestRosterLoaRun()->status)->toBe(SyncRunStatus::Success)
            ->and(latestRosterLoaRun()->inactivated)->toBe(1)
            ->and(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-11-07', 'UTC'))->state)->toBe(LoaCoverageState::None);
    });

    it('is deleted when only its own thread is gone, and the others stay', function () {
        colt();
        Trooper::factory()->create(['username' => 'Hart.A', 'forum_user_id' => 7, 'cav_id' => 70]);
        $keep = rosterApiLoa(['threadId' => '101200', 'subject' => ['userId' => '7', 'username' => 'Hart.A', 'milpacId' => '70']]);
        rosterApiReturns([rosterApiLoa(), $keep]);
        rosterSyncRun();

        rosterApiReturns([$keep]);
        rosterSyncRun(full: true);

        expect(Loa::sole()->forum_thread_id)->toBe(101200);
    });

    it('is not deleted by an hourly run, which reads only two weeks', function () {
        loaStoredThenDeleted();

        rosterApiReturns([]);
        rosterSyncRun();

        expect(Loa::count())->toBe(1)->and(latestRosterLoaRun()->inactivated)->toBe(0);
    });

    it('is not deleted when the full run could not use a record, since that record may be the thread', function () {
        loaStoredThenDeleted();

        rosterApiReturns([rosterApiLoa(['threadId' => '', 'forumUrl' => ''])]);
        rosterSyncRun(full: true);

        expect(Loa::count())->toBe(1)
            ->and(latestRosterLoaRun()->status)->toBe(SyncRunStatus::Partial)
            ->and(latestRosterLoaRun()->errored)->toBe(1)
            ->and(latestRosterLoaRun()->inactivated)->toBe(0);
    });

    it('is not deleted when the call fails, and the run is recorded as failed', function (int $status) {
        loaStoredThenDeleted();

        rosterApiReturns(MockResponse::make(['errors' => []], $status));

        expect(fn () => rosterSyncRun(full: true))->toThrow(Exception::class);
        expect(Loa::count())->toBe(1)
            ->and(latestRosterLoaRun()->status)->toBe(SyncRunStatus::Failure)
            ->and(latestRosterLoaRun()->error)->toContain("HTTP {$status}");
    })->with([401, 403, 500]);

    it('is never deleted from the list a thread-less answer implies for rows the API would not have listed', function () {
        $trooper = colt();
        // Ended before the window the full run asks for: absent from the list whether or not its thread exists.
        $ended = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 3001, 'starts_at' => '2025-09-01', 'ends_at' => '2025-09-08', 'posted_at' => '2025-08-30 08:00:00']);
        // Posted before the window.
        $early = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 3002, 'starts_at' => '2025-11-12', 'ends_at' => '2025-11-14', 'posted_at' => '2025-11-01 08:00:00']);
        // No posted_at, so it cannot be placed in the window.
        $undated = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 3003, 'starts_at' => '2026-11-02', 'ends_at' => '2026-11-04', 'posted_at' => null]);
        // An ELOA is not in the roster API's list at all.
        $eloa = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 3004, 'type' => LoaType::Eloa, 'forum_node_id' => 341, 'starts_at' => '2026-11-01', 'ends_at' => null, 'posted_at' => '2026-11-01 08:00:00']);
        $gone = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 3005, 'starts_at' => '2026-11-02', 'ends_at' => '2026-11-04', 'posted_at' => '2026-11-01 08:00:00']);
        rosterApiReturns([]);

        rosterSyncRun(full: true);

        expect(Loa::query()->whereKey([$ended->id, $early->id, $undated->id, $eloa->id])->count())->toBe(4)
            ->and(Loa::query()->whereKey($gone->id)->exists())->toBeFalse();
    });

    it('deletes a needs-review row as well as a clean one, when its thread is gone', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => null, 'forum_thread_id' => 3001, 'posted_at' => '2026-11-01 08:00:00']);
        rosterApiReturns([]);

        rosterSyncRun(full: true);

        expect(Loa::count())->toBe(0);
    });

    it('is deleted too when the very first run is the one that finds it gone', function () {
        colt();
        Loa::factory()->create(['forum_thread_id' => 3001, 'starts_at' => '2026-11-02', 'ends_at' => '2026-11-04', 'posted_at' => '2026-11-01 08:00:00']);
        rosterApiReturns([]);

        rosterSyncRun();

        expect(Loa::count())->toBe(0)->and(latestRosterLoaRun()->inactivated)->toBe(1);
    });

    it('comes back as a new row if the thread is restored', function () {
        loaStoredThenDeleted();
        rosterApiReturns([]);
        rosterSyncRun(full: true);
        expect(Loa::count())->toBe(0);

        rosterApiReturns([rosterApiLoa()]);
        rosterSyncRun();

        expect(Loa::sole()->forum_thread_id)->toBe(101150);
    });
});

describe('when the roster API is not set up', function () {
    it('skips the LOA half without sending anything or failing, so an ELOA-only install still runs', function () {
        $client = rosterApiReturns([rosterApiLoa()]);
        config(['services.cav.api_key' => null]);
        app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => null]));

        app(SyncLoasHandler::class)->handle('system', true);

        expect(latestRosterLoaRun()->status)->toBe(SyncRunStatus::Success)->and(Loa::count())->toBe(0);
        $client->assertNothingSent();
    });
});
