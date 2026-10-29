<?php

use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
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
 * Story 4.2: the leave sync end to end, against a faked forum. Saloon's URL matching ignores the query
 * string, so a list is answered by a closure that reads the requested page. The thread bodies are real
 * 7Cav posts (tests/Fixtures/forum) with the usernames changed.
 */
const LOA_TITLE_SMITH = '[LOA Request] - PVT.Smith.J | Oct 6, 2026 - Oct 20, 2026';

function loaBody(string $fixture): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/forum/'.$fixture.'.bbcode');
}

/** One thread as the forum would send it: its list entry and its body. */
function loaThread(int $id, string $title, string $body, string $postedAt, int $node = 180, string $state = 'visible'): array
{
    return [
        'summary' => [
            'thread_id' => $id, 'node_id' => $node, 'title' => $title, 'discussion_state' => $state,
            'post_date' => CarbonImmutable::parse($postedAt, 'UTC')->getTimestamp(), 'view_url' => "https://7cav.us/threads/{$id}/",
        ],
        'body' => $body,
    ];
}

function bindForum(array $config = []): ForumApi
{
    $forum = new ForumApi([
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://portal.test/cb',
        'base_url' => 'https://7cav.us', 'authorize_url' => 'https://7cav.us/oauth2/authorize', 'scopes' => ['user:read'],
        'api_key' => 'forum-key', 'loa_nodes' => [180 => 'loa', 341 => 'eloa'], 'paf_node' => 365,
        ...$config,
    ]);
    app()->instance(ForumApi::class, $forum);

    return $forum;
}

/**
 * Fake the forum. `$nodes` is node id => pages (each page a list of threads, newest first) or a MockResponse
 * to answer the list with. `$threads` is thread id => a thread from loaThread() or a MockResponse. `$pafs` is
 * thread id => [postedAt, node] or a MockResponse.
 */
function forumWith(array $nodes, array $threads = [], array $pafs = []): MockClient
{
    // A second global mock does not replace the first one's responses, so each setup starts clean.
    MockClient::destroyGlobal();

    $map = [];

    foreach ([180, 341] as $node) {
        $pages = $nodes[$node] ?? [[]];

        $map["7cav.us/api/forums/{$node}/threads*"] = $pages instanceof MockResponse
            ? $pages
            : function (PendingRequest $request) use ($pages) {
                $page = (int) ($request->query()->get('page') ?? 1);

                return MockResponse::make([
                    'threads' => array_map(fn (array $thread) => $thread['summary'], $pages[$page - 1] ?? []),
                    'pagination' => ['current_page' => $page, 'last_page' => count($pages)],
                ]);
            };
    }

    foreach ($threads as $id => $thread) {
        $map["7cav.us/api/threads/{$id}"] = $thread instanceof MockResponse
            ? $thread
            : MockResponse::make(['thread' => $thread['summary'], 'first_post' => ['message' => $thread['body']]]);
    }

    foreach ($pafs as $id => $paf) {
        $map["7cav.us/api/threads/{$id}"] = $paf instanceof MockResponse
            ? $paf
            : MockResponse::make(['thread' => [
                'thread_id' => $id, 'node_id' => $paf[1] ?? 365, 'title' => 'PAF', 'discussion_state' => 'visible',
                'post_date' => CarbonImmutable::parse($paf[0], 'UTC')->getTimestamp(),
            ]]);
    }

    return MockClient::global($map);
}

/** Every endpoint the run asked the forum for, in order. */
function askedFor(MockClient $client): array
{
    return array_map(fn ($response) => $response->getPendingRequest()->getRequest()->resolveEndpoint(), $client->getRecordedResponses());
}

function runLoaSync(int|string $by = 'system', bool $full = false): void
{
    app(SyncLoasHandler::class)->handle($by, $full);
}

function latestLoaRun(): SyncRun
{
    return SyncRun::where('source', 'loa')->orderByDesc('id')->firstOrFail();
}

function eloaThread(int $id = 5001, string $postedAt = '2026-09-23 09:00', ?string $body = null): array
{
    return loaThread($id, '[ELOA Report] - PFC.Lane.M', $body ?? loaBody('eloa-report'), $postedAt, 341);
}

function returnThread(int $id = 5500, string $postedAt = '2026-10-03 09:00'): array
{
    return loaThread($id, '[ELOA Return] - 1SG.Fox.R', loaBody('eloa-return'), $postedAt, 341);
}

function foxEloa(int $id = 5002, string $postedAt = '2026-09-23 09:00'): array
{
    return loaThread($id, '[ELOA Report] - 1SG.Fox.R', str_replace('Lane.M', 'Fox.R', loaBody('eloa-report')), $postedAt, 341);
}

beforeEach(function () {
    MockClient::destroyGlobal();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    bindForum();
});

describe('a normal LOA', function () {
    it('is stored read cleanly, tied to the trooper, with where it came from', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        $loa = Loa::sole();
        expect($loa->trooper_id)->toBe($trooper->id)
            ->and($loa->forum_thread_id)->toBe(4001)
            ->and($loa->forum_node_id)->toBe(180)
            ->and($loa->type)->toBe(LoaType::Loa)
            ->and($loa->parse_status)->toBe(LoaParseStatus::Ok)
            ->and($loa->review_reason)->toBeNull()
            ->and($loa->starts_at->toDateString())->toBe('2026-10-06')
            ->and($loa->ends_at->toDateString())->toBe('2026-10-20')
            ->and($loa->source_thread)->toBe('https://7cav.us/threads/4001/')
            ->and($loa->posted_at->toDateTimeString())->toBe('2026-10-05 08:00:00')
            ->and($loa->raw_username)->toBe('Smith.J')
            ->and($loa->raw_start_date)->toBe('Oct 6, 2026')
            ->and($loa->raw_end_date)->toBe('Oct 20, 2026')
            ->and($loa->raw_excerpt)->not->toContain('A reason the trooper typed in');
    });

    it('leaves the data as it was when the same run is repeated', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC'));
        runLoaSync();

        $second = latestLoaRun();
        expect(Loa::count())->toBe(1)
            ->and($second->inserted)->toBe(0)
            ->and($second->updated)->toBe(0)
            ->and($second->status)->toBe(SyncRunStatus::Success);
    });

    it('records the run, with the user who triggered it', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync(42);

        $run = latestLoaRun();
        expect($run->status)->toBe(SyncRunStatus::Success)
            ->and($run->triggered_by)->toBe('42')
            ->and($run->inserted)->toBe(1)
            ->and($run->errored)->toBe(0)
            ->and($run->needs_review)->toBe(0)
            ->and($run->finished_at)->not->toBeNull();
    });

    it('stores a thread whose dates cannot be read as needs_review with no dates, and the run still succeeds', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $body = str_replace('Oct 20, 2026', 'whenever I am back', loaBody('loa-on-behalf'));
        $thread = loaThread(4001, '[LOA Request] - PVT.Smith.J', $body, '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        $loa = Loa::sole();
        $run = latestLoaRun();
        expect($loa->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($loa->starts_at)->toBeNull()
            ->and($loa->ends_at)->toBeNull()
            ->and($loa->posted_at->toDateTimeString())->toBe('2026-10-05 08:00:00')
            ->and($loa->raw_end_date)->toBe('whenever I am back')
            ->and($loa->review_reason)->toContain('End Date')
            ->and($loa->trooper_id)->not->toBeNull()
            ->and($run->status)->toBe(SyncRunStatus::Success)
            ->and($run->needs_review)->toBe(1)
            ->and($run->errored)->toBe(0);
    });

    it('stores a one-day LOA as needs_review with no dates, so it excuses no week', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $body = str_replace('Oct 20, 2026', 'Oct 6, 2026', loaBody('loa-on-behalf'));
        $thread = loaThread(4001, '[LOA Request] - PVT.Smith.J | Oct 6, 2026 - Oct 6, 2026', $body, '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        $loa = Loa::sole();
        expect($loa->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($loa->starts_at)->toBeNull()
            ->and($loa->ends_at)->toBeNull()
            ->and($loa->raw_start_date)->toBe('Oct 6, 2026')
            ->and($loa->raw_end_date)->toBe('Oct 6, 2026')
            ->and($loa->review_reason)->toContain('shorter than one Cav week')
            ->and(latestLoaRun()->needs_review)->toBe(1);
    });

    it('excuses just the Cav week of a Saturday to Friday LOA, the shortest one allowed', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $body = str_replace(['Oct 6, 2026', 'Oct 20, 2026'], ['Oct 3, 2026', 'Oct 9, 2026'], loaBody('loa-on-behalf'));
        $thread = loaThread(4001, '[LOA Request] - PVT.Smith.J | Oct 3, 2026 - Oct 9, 2026', $body, '2026-10-02 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        $weeks = app(LoaCoverage::class)->forTroopersOverWeeks([$trooper->id], CarbonImmutable::parse('2026-09-26', 'UTC'), 3)[$trooper->id];
        expect(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok)
            ->and(array_map(fn ($cover) => $cover->state, $weeks))->toBe([
                '2026-09-26' => LoaCoverageState::None,
                '2026-10-03' => LoaCoverageState::Covered,
                '2026-10-10' => LoaCoverageState::None,
            ]);
    });

    it('flips a thread that read cleanly to needs_review when it is edited into something unreadable', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $good = loaThread(4001, '[LOA Request] - PVT.Smith.J', loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$good]]], [4001 => $good]);
        runLoaSync();
        expect(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok);
        $id = Loa::sole()->id;

        $edited = loaThread(4001, '[LOA Request] - PVT.Smith.J', str_replace('Oct 20, 2026', 'soon', loaBody('loa-on-behalf')), '2026-10-05 08:00');
        forumWith([180 => [[$edited]]], [4001 => $edited]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC'));
        runLoaSync(1, true);

        $loa = Loa::sole();
        expect($loa->id)->toBe($id)
            ->and($loa->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($loa->starts_at)->toBeNull()
            ->and($loa->ends_at)->toBeNull();
    });

    it('keeps a thread with an unrecognised title tag, as needs_review, for a person to look at', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, '[LOA Cancel] - PVT.Smith.J', loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and(Loa::sole()->review_reason)->toContain('does not start with')
            ->and(Loa::sole()->type)->toBe(LoaType::Loa);
    });
});

describe('finding the trooper by username', function () {
    it('matches the whole name, so Perry.J and Perry.JW are never confused', function () {
        $j = Trooper::factory()->create(['username' => 'Perry.J']);
        $jw = Trooper::factory()->create(['username' => 'Perry.JW']);
        $body = fn (string $name) => "Username: {$name}\nStart Date\nOct 6, 2026\nEnd Date\nOct 20, 2026\n";
        $one = loaThread(4001, '[LOA Request] - PVT.Perry.J', $body('Perry.J'), '2026-10-05 08:00');
        $two = loaThread(4002, '[LOA Request] - PVT.Perry.JW', $body('Perry.JW'), '2026-10-05 09:00');
        forumWith([180 => [[$two, $one]]], [4001 => $one, 4002 => $two]);

        runLoaSync();

        expect(Loa::where('forum_thread_id', 4001)->sole()->trooper_id)->toBe($j->id)
            ->and(Loa::where('forum_thread_id', 4002)->sole()->trooper_id)->toBe($jw->id);
    });

    it('matches ignoring case', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, '[LOA Request] - PVT.SMITH.J', "Username: SMITH.J\nStart Date\nOct 6, 2026\nEnd Date\nOct 20, 2026\n", '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->trooper_id)->toBe($trooper->id)->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok);
    });

    it('keeps a thread whose username matches no trooper as needs_review with no trooper, never guessing one', function () {
        Trooper::factory()->create(['username' => 'Someone.Else']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        $loa = Loa::sole();
        expect($loa->trooper_id)->toBeNull()
            ->and($loa->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($loa->starts_at)->toBeNull()
            ->and($loa->review_reason)->toContain('No trooper is named Smith.J')
            ->and($loa->raw_username)->toBe('Smith.J')
            ->and($loa->forum_node_id)->toBe(180)
            ->and(latestLoaRun()->needs_review)->toBe(1);
    });

    it('keeps a thread whose title names someone other than its Username as needs_review, with no dates', function () {
        $hart = Trooper::factory()->create(['username' => 'Hart.A']);
        Trooper::factory()->create(['username' => 'Evil.X']);
        $thread = loaThread(4001, '[LOA Request] - PVT.Evil.X | Oct 3, 2026 - Oct 17, 2026', loaBody('loa-self'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        $loa = Loa::sole();
        expect($loa->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($loa->review_reason)->toBe('The title names PVT.Evil.X but the post names Hart.A.')
            ->and($loa->trooper_id)->toBe($hart->id)
            ->and($loa->starts_at)->toBeNull()
            ->and($loa->ends_at)->toBeNull()
            ->and(latestLoaRun()->needs_review)->toBe(1);
    });

    it('refuses to pick between two troopers who carry the same name', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        Trooper::factory()->create(['username' => 'smith.j']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->trooper_id)->toBeNull()
            ->and(Loa::sole()->review_reason)->toContain('2 troopers are named Smith.J');
    });
});

describe('finding the trooper by forum user id', function () {
    it('prefers the id in the [USER=id] mention, so a renamed trooper still gets their leave', function () {
        $renamed = Trooper::factory()->create(['username' => 'Smith.JR', 'forum_user_id' => 11111]);
        $namesake = Trooper::factory()->create(['username' => 'Smith.J', 'forum_user_id' => 99999]);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->trooper_id)->toBe($renamed->id)->and($namesake->id)->not->toBe($renamed->id);
    });

    it('falls back to the username when no trooper carries that forum id', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J', 'forum_user_id' => null]);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->trooper_id)->toBe($trooper->id);
    });
});

describe('raw text longer than the portal keeps', function () {
    it('cuts an over-long Start Date line to its column and sends the row to review, and the rest of the run is stored', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $hart = Trooper::factory()->create(['username' => 'Hart.A']);
        $long = str_repeat('a', 300);
        $rambling = loaThread(4001, '[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\n{$long}\nEnd Date\nOct 20, 2026\n", '2026-10-05 08:00');
        $good = loaThread(4002, '[LOA Request] - MAJ.Hart.A | Oct 3, 2026 - Oct 17, 2026', loaBody('loa-self'), '2026-10-05 09:00');
        forumWith([180 => [[$good, $rambling]]], [4001 => $rambling, 4002 => $good]);

        runLoaSync();

        $cut = Loa::where('forum_thread_id', 4001)->sole();
        $stored = Loa::where('forum_thread_id', 4002)->sole();
        expect($cut->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($cut->raw_start_date)->toBe(str_repeat('a', 255))
            ->and($cut->review_reason)->toContain('The Start Date is longer than the portal keeps.')
            ->and($cut->starts_at)->toBeNull()
            ->and($stored->parse_status)->toBe(LoaParseStatus::Ok)
            ->and($stored->trooper_id)->toBe($hart->id)
            ->and(latestLoaRun()->status)->toBe(SyncRunStatus::Success)
            ->and(latestLoaRun()->needs_review)->toBe(1);
    });

    it('cuts an over-long End Date or Username the same way, and looks the trooper up by the cut name', function () {
        $long = str_repeat('b', 300);
        $longEnd = loaThread(4001, '[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nOct 6, 2026\nEnd Date\n{$long}\n", '2026-10-05 08:00');
        $longName = loaThread(4002, '[LOA Request] - PVT.Smith.J', "Username: {$long}\nStart Date\nOct 6, 2026\nEnd Date\nOct 20, 2026\n", '2026-10-05 09:00');
        Trooper::factory()->create(['username' => 'Smith.J']);
        forumWith([180 => [[$longName, $longEnd]]], [4001 => $longEnd, 4002 => $longName]);

        runLoaSync();

        $end = Loa::where('forum_thread_id', 4001)->sole();
        $name = Loa::where('forum_thread_id', 4002)->sole();
        expect($end->raw_end_date)->toBe(str_repeat('b', 255))
            ->and($end->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($end->review_reason)->toContain('The End Date is longer than the portal keeps.')
            ->and($name->raw_username)->toBe(str_repeat('b', 255))
            ->and($name->trooper_id)->toBeNull()
            ->and($name->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($name->review_reason)->toContain('The Username is longer than the portal keeps.')
            ->and(mb_strlen($name->review_reason))->toBeLessThanOrEqual(250);
    });

    it('counts the limit in characters, so a long line of accented letters is kept whole up to it', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $fits = str_repeat('é', 255);
        $thread = loaThread(4001, '[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nOct 6, 2026\nEnd Date\n{$fits}\n", '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->raw_end_date)->toBe($fits)
            ->and(Loa::sole()->review_reason)->not->toContain('longer than the portal keeps');
    });

    it('stores the plain thread address when the forum\'s own is too long for the column', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        $thread['summary']['view_url'] = 'https://7cav.us/threads/'.str_repeat('a-very-long-slug-', 20).'4001/';
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync();

        expect(Loa::sole()->source_thread)->toBe('https://7cav.us/threads/4001/')
            ->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok);
    });
});

describe('an ELOA', function () {

    it('starts the day its PAF was filed, which can be a day before the report was posted', function () {
        $trooper = Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        $client = forumWith([341 => [[$report]]], [5001 => $report], [106503 => ['2026-09-22 20:30']]);

        runLoaSync();

        $loa = Loa::sole();
        expect($loa->type)->toBe(LoaType::Eloa)
            ->and($loa->trooper_id)->toBe($trooper->id)
            ->and($loa->forum_node_id)->toBe(341)
            ->and($loa->parse_status)->toBe(LoaParseStatus::Ok)
            ->and($loa->starts_at->toDateString())->toBe('2026-09-22')
            ->and($loa->ends_at)->toBeNull()
            ->and($loa->raw_start_date)->toBe('PAF 106503 filed 2026-09-22')
            ->and($loa->raw_end_date)->toBe('Nov 13, 2026')
            ->and(askedFor($client))->toContain('/api/threads/106503');
    });

    it('asks the forum for only the PAF thread\'s own date, never its first post', function () {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        $client = forumWith([341 => [[$report]]], [5001 => $report], [106503 => ['2026-09-22 20:30']]);

        runLoaSync();

        $pafRequest = collect($client->getRecordedResponses())
            ->map(fn ($response) => $response->getPendingRequest())
            ->first(fn ($pending) => $pending->getRequest()->resolveEndpoint() === '/api/threads/106503');

        expect($pafRequest->query()->all())->toBe([]);
    });

    it('does not end at the expected return date, which is only an expectation, but keeps it for display', function () {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        forumWith([341 => [[$report]]], [5001 => $report], [106503 => ['2026-09-22 20:30']]);

        runLoaSync();

        expect(Loa::sole()->ends_at)->toBeNull()
            ->and(Loa::sole()->expected_return_at->toDateString())->toBe('2026-11-13');
    });

    it('keeps the expected return when an extension is posted by editing it', function () {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        forumWith([341 => [[$report]]], [5001 => $report], [106503 => ['2026-09-22 20:30']]);
        runLoaSync();

        $extended = eloaThread(5001, '2026-09-23 09:00', str_replace('Nov 13, 2026', 'Dec 18, 2026', loaBody('eloa-report')));
        forumWith([341 => [[$extended]]], [5001 => $extended], [106503 => ['2026-09-22 20:30']]);
        runLoaSync(full: true);

        expect(Loa::sole()->expected_return_at->toDateString())->toBe('2026-12-18');
    });

    it('falls back to the day the report was posted when the PAF link is missing, and says so', function () {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $body = preg_replace('/\[B\]PAF LINK\[\/B\].*/', '', loaBody('eloa-report'));
        $report = eloaThread(5001, '2026-09-23 09:00', $body);
        forumWith([341 => [[$report]]], [5001 => $report]);

        runLoaSync();

        expect(Loa::sole()->starts_at->toDateString())->toBe('2026-09-23')
            ->and(Loa::sole()->raw_start_date)->toBe('posted 2026-09-23 (no PAF link)')
            ->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok);
    });

    it('falls back when the forum will not show the PAF, forbidden or not found, and says so', function (int $status) {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        forumWith([341 => [[$report]]], [5001 => $report], [106503 => MockResponse::make(['errors' => [['code' => 'no_permission']]], $status)]);

        runLoaSync();

        expect(Loa::sole()->starts_at->toDateString())->toBe('2026-09-23')
            ->and(Loa::sole()->raw_start_date)->toBe('posted 2026-09-23 (PAF 106503 not readable)')
            ->and(latestLoaRun()->errored)->toBe(0);
    })->with([403, 404]);

    it('falls back when the linked thread is not in the PAF section', function () {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        forumWith([341 => [[$report]]], [5001 => $report], [106503 => ['2026-09-22 20:30', 12]]);

        runLoaSync();

        expect(Loa::sole()->raw_start_date)->toBe('posted 2026-09-23 (PAF 106503 not readable)');
    });

    it('falls back when no PAF section is configured', function () {
        bindForum(['paf_node' => null]);
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        $client = forumWith([341 => [[$report]]], [5001 => $report]);

        runLoaSync();

        expect(Loa::sole()->raw_start_date)->toBe('posted 2026-09-23 (PAF section not configured)')
            ->and(askedFor($client))->not->toContain('/api/threads/106503');
    });

    it('skips the thread, to retry next run, when the PAF fails for a temporary reason, instead of dating it by the fallback', function (int $status) {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $report = eloaThread();
        forumWith([341 => [[$report]]], [5001 => $report], [106503 => MockResponse::make([], $status)]);

        runLoaSync();

        $run = latestLoaRun();
        expect(Loa::count())->toBe(0)
            ->and($run->status)->toBe(SyncRunStatus::Partial)
            ->and($run->errored)->toBe(1)
            ->and($run->error)->toContain('PAF 106503');
    })->with([500, 502, 503, 429]);

    it('looks up a PAF shared by several threads once per run', function () {
        Trooper::factory()->create(['username' => 'Lane.M']);
        $one = eloaThread(5001);
        $two = eloaThread(5002, '2026-09-24 09:00');
        $client = forumWith([341 => [[$two, $one]]], [5001 => $one, 5002 => $two], [106503 => ['2026-09-22 20:30']]);

        runLoaSync();

        expect(collect(askedFor($client))->filter(fn ($endpoint) => $endpoint === '/api/threads/106503'))->toHaveCount(1);
    });

    it('needs review, with no start, when its username matches no trooper', function () {
        $report = eloaThread();
        forumWith([341 => [[$report]]], [5001 => $report], [106503 => ['2026-09-22 20:30']]);

        runLoaSync();

        expect(Loa::sole()->trooper_id)->toBeNull()
            ->and(Loa::sole()->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and(Loa::sole()->starts_at)->toBeNull();
    });
});

describe('an ELOA return', function () {

    it('ends the trooper\'s open ELOA on the day its own PAF was filed, and is not stored as a row', function () {
        $trooper = Trooper::factory()->create(['username' => 'Fox.R']);
        $report = foxEloa();
        $return = returnThread();
        forumWith([341 => [[$return, $report]]], [5002 => $report, 5500 => $return], [106503 => ['2026-09-22 20:30'], 106812 => ['2026-10-02 18:00']]);

        runLoaSync();

        $loa = Loa::sole();
        $run = latestLoaRun();
        expect($loa->forum_thread_id)->toBe(5002)
            ->and($loa->trooper_id)->toBe($trooper->id)
            ->and($loa->starts_at->toDateString())->toBe('2026-09-22')
            ->and($loa->ends_at->toDateString())->toBe('2026-10-02')
            ->and($loa->return_thread_id)->toBe(5500)
            ->and($run->inserted)->toBe(1)
            ->and($run->inactivated)->toBe(0);
    });

    it('falls back to the day the return was posted when its PAF cannot be read', function () {
        Trooper::factory()->create(['username' => 'Fox.R']);
        $report = foxEloa();
        $return = returnThread();
        forumWith([341 => [[$return, $report]]], [5002 => $report, 5500 => $return], [106503 => ['2026-09-22 20:30'], 106812 => MockResponse::make([], 404)]);

        runLoaSync();

        expect(Loa::sole()->ends_at->toDateString())->toBe('2026-10-03');
    });

    it('does not reopen an ended ELOA when its report is read again later, or when the return is seen again', function () {
        Trooper::factory()->create(['username' => 'Fox.R']);
        $report = foxEloa();
        $return = returnThread();
        forumWith([341 => [[$return, $report]]], [5002 => $report, 5500 => $return], [106503 => ['2026-09-22 20:30'], 106812 => ['2026-10-02 18:00']]);
        runLoaSync();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
        runLoaSync(1, true);

        $loa = Loa::sole();
        expect($loa->ends_at->toDateString())->toBe('2026-10-02')
            ->and($loa->return_thread_id)->toBe(5500)
            ->and(latestLoaRun()->updated)->toBe(0);
    });

    it('is applied on a later run when the return is posted after the ELOA was stored', function () {
        $trooper = Trooper::factory()->create(['username' => 'Fox.R']);
        $report = foxEloa();
        forumWith([341 => [[$report]]], [5002 => $report], [106503 => ['2026-09-22 20:30']]);
        runLoaSync();
        expect(Loa::sole()->ends_at)->toBeNull();

        $return = returnThread(5500, '2026-10-08 12:30');
        forumWith([341 => [[$return, $report]]], [5500 => $return, 5002 => $report], [106812 => ['2026-10-06 18:00']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC'));
        runLoaSync();

        expect(Loa::sole()->ends_at->toDateString())->toBe('2026-10-06')->and(Loa::sole()->trooper_id)->toBe($trooper->id);
    });

    it('is counted for a person, and stored nowhere, when there is no open ELOA to end', function () {
        Trooper::factory()->create(['username' => 'Fox.R']);
        $return = returnThread();
        forumWith([341 => [[$return]]], [5500 => $return], [106812 => ['2026-10-02 18:00']]);

        runLoaSync();

        expect(Loa::count())->toBe(0)->and(latestLoaRun()->needs_review)->toBe(1)->and(latestLoaRun()->status)->toBe(SyncRunStatus::Success);
    });

    it('is counted for a person when its username matches no trooper', function () {
        $return = returnThread();
        forumWith([341 => [[$return]]], [5500 => $return], [106812 => ['2026-10-02 18:00']]);

        runLoaSync();

        expect(Loa::count())->toBe(0)->and(latestLoaRun()->needs_review)->toBe(1);
    });
});

describe('which threads a run reads', function () {
    it('goes back a year on the first run, and no further', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $recent = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-09-01 08:00');
        $oldButInside = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2025-10-15 08:00');
        $tooOld = loaThread(4003, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2025-10-01 08:00');
        $client = forumWith([180 => [[$recent, $oldButInside, $tooOld]]], [4001 => $recent, 4002 => $oldButInside, 4003 => $tooOld]);

        runLoaSync();

        expect(askedFor($client))->toContain('/api/threads/4001', '/api/threads/4002')->not->toContain('/api/threads/4003');
    });

    it('reads only the threads newer than the last good run after that, stopping the listing at the first older one', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $old = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-01 08:00');
        forumWith([180 => [[$old]]], [4001 => $old]);
        runLoaSync();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC'));
        $new = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-08 15:00');
        $client = forumWith([180 => [[$new, $old], [$old]]], [4002 => $new, 4001 => $old]);
        runLoaSync();

        $asked = askedFor($client);
        expect($asked)->toContain('/api/threads/4002')
            ->not->toContain('/api/threads/4001')
            ->and(collect($asked)->filter(fn ($endpoint) => $endpoint === '/api/forums/180/threads')->count())->toBe(1);
    });

    it('keeps a short overlap, so a thread posted while the last run was running is not missed, and no more', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $first = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-06 09:00');
        forumWith([180 => [[$first]]], [4001 => $first]);
        runLoaSync();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC'));
        // The last run began at 12:00, so the cutoff is 09:00: a thread from 09:30 is inside it, one from 08:00 is not.
        $justInside = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-08 09:30');
        $justOutside = loaThread(4003, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-08 08:00');
        $client = forumWith([180 => [[$justInside, $justOutside, $first]]], [4002 => $justInside, 4003 => $justOutside, 4001 => $first]);
        runLoaSync();

        expect(askedFor($client))->toContain('/api/threads/4002')->not->toContain('/api/threads/4003', '/api/threads/4001');
    });

    it('goes back the full year on a full run, whatever the last good run was', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $old = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-03-01 08:00');
        forumWith([180 => [[$old]]], [4001 => $old]);
        runLoaSync();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC'));
        $client = forumWith([180 => [[$old]]], [4001 => $old]);

        runLoaSync(1, true);

        expect(askedFor($client))->toContain('/api/threads/4001');
    });

    it('skips old, finished leave on a full run, but re-reads recent, open and unreadable threads', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $finished = loaThread(4001, '[LOA Request] - PVT.Smith.J | Mar 1, 2026 - Mar 10, 2026', "Username: Smith.J\nStart Date\nMar 1, 2026\nEnd Date\nMar 10, 2026\n", '2026-02-25 08:00');
        $recentlyEnded = loaThread(4002, '[LOA Request] - PVT.Smith.J | Sep 25, 2026 - Oct 2, 2026', "Username: Smith.J\nStart Date\nSep 25, 2026\nEnd Date\nOct 2, 2026\n", '2026-09-22 08:00');
        $ongoing = loaThread(4003, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        $unreadable = loaThread(4004, '[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nsoon\nEnd Date\nlater\n", '2026-04-01 08:00');
        $threads = [4001 => $finished, 4002 => $recentlyEnded, 4003 => $ongoing, 4004 => $unreadable];
        forumWith([180 => [[$ongoing, $recentlyEnded, $unreadable, $finished]]], $threads);
        runLoaSync(1, true);
        expect(Loa::count())->toBe(4);

        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
        $client = forumWith([180 => [[$ongoing, $recentlyEnded, $unreadable, $finished]]], $threads);
        runLoaSync(1, true);

        $asked = askedFor($client);
        expect($asked)->toContain('/api/threads/4002', '/api/threads/4003', '/api/threads/4004')->not->toContain('/api/threads/4001');
    });

    it('does not skip anything on an incremental run, where the cutoff already limits what is read', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $finished = loaThread(4001, '[LOA Request] - PVT.Smith.J | Mar 1, 2026 - Mar 10, 2026', "Username: Smith.J\nStart Date\nMar 1, 2026\nEnd Date\nMar 10, 2026\n", '2026-10-07 08:00');
        $client = forumWith([180 => [[$finished]]], [4001 => $finished]);

        runLoaSync();

        expect(askedFor($client))->toContain('/api/threads/4001');
    });

    it('walks on to the next page when the first has nothing older', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $one = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-07 08:00');
        $two = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-06 08:00');
        $client = forumWith([180 => [[$one], [$two]]], [4001 => $one, 4002 => $two]);

        runLoaSync();

        expect(Loa::count())->toBe(2)
            ->and(collect(askedFor($client))->filter(fn ($endpoint) => $endpoint === '/api/forums/180/threads')->count())->toBe(2);
    });

    it('stops at the page limit and says so, rather than walking a section forever', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $page = fn (int $n) => [loaThread(4000 + $n, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-07 08:00')];
        $pages = array_map($page, range(1, 45));
        forumWith([180 => $pages], collect($pages)->mapWithKeys(fn ($threads) => [$threads[0]['summary']['thread_id'] => $threads[0]])->all());

        runLoaSync();

        $run = latestLoaRun();
        expect(Loa::count())->toBe(40)->and($run->status)->toBe(SyncRunStatus::Partial)->and($run->error)->toContain('40-page limit');
    });

    it('reads every configured section, giving each thread the kind of its section', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        Trooper::factory()->create(['username' => 'Lane.M']);
        $loa = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        $eloa = loaThread(5001, '[ELOA Report] - PFC.Lane.M', loaBody('eloa-report'), '2026-10-05 09:00', 341);
        forumWith([180 => [[$loa]], 341 => [[$eloa]]], [4001 => $loa, 5001 => $eloa], [106503 => ['2026-10-04 10:00']]);

        runLoaSync();

        expect(Loa::where('forum_thread_id', 4001)->sole()->type)->toBe(LoaType::Loa)
            ->and(Loa::where('forum_thread_id', 5001)->sole()->type)->toBe(LoaType::Eloa);
    });

    it('skips threads that are not visible', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $gone = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00', 180, 'deleted');
        $moderated = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00', 180, 'moderated');
        $client = forumWith([180 => [[$gone, $moderated]]], [4001 => $gone, 4002 => $moderated]);

        runLoaSync();

        expect(Loa::count())->toBe(0)->and(askedFor($client))->not->toContain('/api/threads/4001', '/api/threads/4002');
    });

    it('does not read pinned notices, which the forum lists apart from the threads', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $real = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        $notice = loaThread(1, 'How to file an LOA', 'Fill in the form.', '2025-11-01 08:00');
        $map = [
            '7cav.us/api/forums/180/threads*' => MockResponse::make(['threads' => [$real['summary']], 'sticky' => [$notice['summary']], 'pagination' => ['current_page' => 1, 'last_page' => 1]]),
            '7cav.us/api/forums/341/threads*' => MockResponse::make(['threads' => [], 'pagination' => ['current_page' => 1, 'last_page' => 1]]),
            '7cav.us/api/threads/4001' => MockResponse::make(['thread' => $real['summary'], 'first_post' => ['message' => $real['body']]]),
        ];
        $client = MockClient::global($map);

        runLoaSync();

        expect(Loa::count())->toBe(1)->and(askedFor($client))->not->toContain('/api/threads/1');
    });
});

describe('a post that needs review but gives its dates', function () {
    it('keeps the dates apart, so the shield sits on the leave and not on the week it was posted', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $ahead = loaThread(4001, '[LOA Request] - PVT.Smith.J | Oct 24, 2026 - Nov 6, 2026', "Username: Smith.J\nStart Date\nOct 24, 2026\nEnd Date\nNov 9, 2026\n", '2026-10-01 08:00');
        forumWith([180 => [[$ahead]]], [4001 => $ahead]);

        runLoaSync();

        $row = Loa::sole();
        $coverage = app(LoaCoverage::class);
        expect($row->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($row->starts_at)->toBeNull()
            ->and($row->review_starts_at->toDateString())->toBe('2026-10-24')
            ->and($row->review_ends_at->toDateString())->toBe('2026-11-07')
            ->and($coverage->forTrooper($trooper->id, CarbonImmutable::parse('2026-10-03', 'UTC'))->state)->toBe(LoaCoverageState::None)
            ->and($coverage->forTrooper($trooper->id, CarbonImmutable::parse('2026-10-31', 'UTC'))->state)->toBe(LoaCoverageState::NeedsReview);
    });

    it('keeps shielding a clean leave edited into needing review, past the week it was posted', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $clean = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$clean]]], [4001 => $clean]);
        runLoaSync(1, true);

        // The trooper extends it in the body only, so the title and the post disagree.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'));
        $edited = loaThread(4001, LOA_TITLE_SMITH, str_replace('Oct 20, 2026', 'Oct 27, 2026', loaBody('loa-on-behalf')), '2026-10-05 08:00');
        forumWith([180 => [[$edited]]], [4001 => $edited]);
        runLoaSync(1, true);

        $row = Loa::sole();
        expect($row->parse_status)->toBe(LoaParseStatus::NeedsReview)
            ->and($row->review_starts_at->toDateString())->toBe('2026-10-06')
            ->and(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-10-17', 'UTC'))->state)->toBe(LoaCoverageState::NeedsReview);
    });

    it('clears them once the post reads cleanly again', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $broken = loaThread(4001, '[LOA Request] - PVT.Smith.J | Oct 6, 2026 - Oct 13, 2026', loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$broken]]], [4001 => $broken]);
        runLoaSync(1, true);
        expect(Loa::sole()->review_starts_at)->not->toBeNull();

        $fixed = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$fixed]]], [4001 => $fixed]);
        runLoaSync(1, true);

        expect(Loa::sole()->parse_status)->toBe(LoaParseStatus::Ok)
            ->and(Loa::sole()->review_starts_at)->toBeNull()
            ->and(Loa::sole()->review_ends_at)->toBeNull();
    });
});

describe('a thread that goes away', function () {
    /** One clean LOA for Smith.J, stored by a first full run, then a day passes. */
    function storedThenGone(): Trooper
    {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);
        runLoaSync(1, true);
        test()->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));

        return $trooper;
    }

    it('stops counting a leave whose thread a clean full run no longer finds, and keeps the row', function () {
        $trooper = storedThenGone();
        expect(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-10-10', 'UTC'))->state)->toBe(LoaCoverageState::Covered);

        forumWith([180 => [[]]]);
        runLoaSync(1, true);

        $row = Loa::sole();
        expect($row->withdrawn_at)->not->toBeNull()
            ->and($row->parse_status)->toBe(LoaParseStatus::Ok)
            ->and(latestLoaRun()->status)->toBe(SyncRunStatus::Success)
            ->and(latestLoaRun()->inactivated)->toBe(1)
            ->and(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-10-10', 'UTC'))->state)->toBe(LoaCoverageState::None);
    });

    it('withdraws a thread the listing shows as deleted or moderated, or the forum says is gone', function (string $state, ?int $status) {
        storedThenGone();
        $listed = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00', 180, $state);
        $fetched = $status === null ? loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00', 180, 'deleted') : MockResponse::make(['errors' => []], $status);
        forumWith([180 => [[$listed]]], [4001 => $fetched]);

        runLoaSync(1, true);

        expect(Loa::sole()->withdrawn_at)->not->toBeNull()->and(latestLoaRun()->status)->toBe(SyncRunStatus::Success);
    })->with([
        'deleted in the listing' => ['deleted', null],
        'moderated in the listing' => ['moderated', null],
        'listed but hidden when read' => ['visible', null],
        'listed but gone when read' => ['visible', 404],
    ]);

    it('keeps a thread moved to the other leave section', function () {
        storedThenGone();
        $moved = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00', 341);
        forumWith([180 => [[]], 341 => [[$moved]]], [4001 => $moved]);

        runLoaSync(1, true);

        expect(Loa::sole()->withdrawn_at)->toBeNull()->and(latestLoaRun()->inactivated)->toBe(0);
    });

    it('withdraws nothing on an incremental run, which does not see every thread', function () {
        storedThenGone();
        forumWith([180 => [[]]]);

        runLoaSync();

        expect(Loa::sole()->withdrawn_at)->toBeNull();
    });

    it('withdraws nothing on a full run that was partial or failed', function () {
        storedThenGone();
        $other = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-06 08:00');
        forumWith([180 => [[$other]]], [4002 => MockResponse::make([], 503)]);
        runLoaSync(1, true);
        expect(latestLoaRun()->status)->toBe(SyncRunStatus::Partial);

        forumWith([180 => [[]], 341 => MockResponse::make([], 503)]);
        expect(fn () => runLoaSync(1, true))->toThrow(ForumRequestException::class);

        expect(Loa::sole()->withdrawn_at)->toBeNull();
    });

    it('leaves rows outside the window it read, and from sections it did not read, alone', function () {
        storedThenGone();
        $old = Loa::factory()->create(['forum_thread_id' => 3001, 'forum_node_id' => 180, 'posted_at' => '2025-09-01 08:00:00']);
        $elsewhere = Loa::factory()->create(['forum_thread_id' => 3002, 'forum_node_id' => 999, 'posted_at' => '2026-10-01 08:00:00']);
        $undated = Loa::factory()->create(['forum_thread_id' => 3003, 'forum_node_id' => 180, 'posted_at' => null]);
        $thread = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4001 => $thread]);

        runLoaSync(1, true);

        expect(Loa::whereNotNull('withdrawn_at')->count())->toBe(0)
            ->and($old->fresh()->withdrawn_at)->toBeNull()
            ->and($elsewhere->fresh()->withdrawn_at)->toBeNull()
            ->and($undated->fresh()->withdrawn_at)->toBeNull();
    });

    it('counts a withdrawn leave again once a later run finds its thread', function () {
        $trooper = storedThenGone();
        forumWith([180 => [[]]]);
        runLoaSync(1, true);
        expect(Loa::sole()->withdrawn_at)->not->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00:00', 'UTC'));
        $back = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$back]]], [4001 => $back]);
        runLoaSync(1, true);

        expect(Loa::sole()->withdrawn_at)->toBeNull()
            ->and(app(LoaCoverage::class)->forTrooper($trooper->id, CarbonImmutable::parse('2026-10-10', 'UTC'))->state)->toBe(LoaCoverageState::Covered);
    });

    it('puts back a settled leave the full run lists but does not re-read', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $finished = loaThread(4001, '[LOA Request] - PVT.Smith.J | Mar 1, 2026 - Mar 10, 2026', "Username: Smith.J\nStart Date\nMar 1, 2026\nEnd Date\nMar 10, 2026\n", '2026-02-25 08:00');
        forumWith([180 => [[$finished]]], [4001 => $finished]);
        runLoaSync(1, true);
        Loa::sole()->forceFill(['withdrawn_at' => now()])->save();

        $client = forumWith([180 => [[$finished]]], [4001 => $finished]);
        runLoaSync(1, true);

        expect(askedFor($client))->not->toContain('/api/threads/4001')
            ->and(Loa::sole()->withdrawn_at)->toBeNull();
    });
});

describe('when something goes wrong', function () {
    it('fails the run, and leaves every existing row alone, when a listing fails', function () {
        $trooper = Trooper::factory()->create(['username' => 'Smith.J']);
        $existing = Loa::factory()->create(['trooper_id' => $trooper->id, 'forum_thread_id' => 3001]);
        forumWith([180 => MockResponse::make(['errors' => [['code' => 'server_error']]], 500)]);

        expect(fn () => runLoaSync())->toThrow(ForumRequestException::class, 'HTTP 500');

        $run = latestLoaRun();
        expect($run->status)->toBe(SyncRunStatus::Failure)
            ->and($run->error)->toContain('HTTP 500')
            ->and(Loa::count())->toBe(1)
            ->and(Loa::sole()->id)->toBe($existing->id);
    });

    it('does not move the cutoff after a failed run, so the next one still covers the gap', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $first = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-09-30 08:00');
        forumWith([180 => [[$first]]], [4001 => $first]);
        runLoaSync();

        // A thread is posted two days after that good run; a run a week later fails.
        $missed = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-03 09:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC'));
        forumWith([180 => MockResponse::make([], 503)]);
        expect(fn () => runLoaSync())->toThrow(ForumRequestException::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
        $client = forumWith([180 => [[$missed, $first]]], [4002 => $missed, 4001 => $first]);
        runLoaSync();

        // Had the failed run moved the cutoff to its own start (less two days, Oct 6), the Oct 3 thread would
        // be skipped for good. The cutoff is still the last good run's, so it is read.
        expect(askedFor($client))->toContain('/api/threads/4002')
            ->and(Loa::where('forum_thread_id', 4002)->exists())->toBeTrue();
    });

    it('writes the other threads and marks the run partial when one thread cannot be fetched', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        Trooper::factory()->create(['username' => 'Hart.A']);
        $fine = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-06 08:00');
        $broken = loaThread(4002, '[LOA Request] - MAJ.Hart.A | Oct 3, 2026 - Oct 17, 2026', loaBody('loa-self'), '2026-10-05 08:00');
        forumWith([180 => [[$fine, $broken]]], [4001 => $fine, 4002 => MockResponse::make(['errors' => []], 503)]);

        runLoaSync();

        $run = latestLoaRun();
        expect(Loa::count())->toBe(1)
            ->and(Loa::sole()->forum_thread_id)->toBe(4001)
            ->and($run->status)->toBe(SyncRunStatus::Partial)
            ->and($run->errored)->toBe(1)
            ->and($run->error)->toContain('Thread 4002');
    });

    it('skips a thread that is gone for good without making the run partial', function (int $status) {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $fine = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-06 08:00');
        $gone = loaThread(4002, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        forumWith([180 => [[$fine, $gone]]], [4001 => $fine, 4002 => MockResponse::make(['errors' => []], $status)]);

        runLoaSync();

        $run = latestLoaRun();
        expect(Loa::sole()->forum_thread_id)->toBe(4001)
            ->and($run->status)->toBe(SyncRunStatus::Success)
            ->and($run->errored)->toBe(0);
    })->with([403, 404, 410]);

    it('keeps the sections already done when a later section fails, and does not move the cutoff', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $first = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-06 08:00');
        forumWith([180 => [[$first]], 341 => MockResponse::make([], 503)], [4001 => $first]);

        expect(fn () => runLoaSync())->toThrow(ForumRequestException::class);

        expect(Loa::sole()->forum_thread_id)->toBe(4001)
            ->and(latestLoaRun()->status)->toBe(SyncRunStatus::Failure);
    });

    it('closes a run left in progress by a killed worker as failed, before starting its own', function () {
        $orphan = SyncRun::query()->forceCreate(['source' => 'loa', 'started_at' => now()->subHours(3), 'status' => SyncRunStatus::InProgress, 'triggered_by' => 'system']);
        forumWith([180 => [[]]]);

        runLoaSync();

        expect($orphan->fresh()->status)->toBe(SyncRunStatus::Failure)
            ->and($orphan->fresh()->finished_at)->not->toBeNull()
            ->and(latestLoaRun()->status)->toBe(SyncRunStatus::Success);
    });

    it('retries a thread that failed once the forum answers, since a partial run does not move the cutoff', function () {
        Trooper::factory()->create(['username' => 'Hart.A']);
        $thread = loaThread(4002, '[LOA Request] - MAJ.Hart.A | Oct 3, 2026 - Oct 17, 2026', loaBody('loa-self'), '2026-10-05 08:00');
        forumWith([180 => [[$thread]]], [4002 => MockResponse::make([], 503)]);
        runLoaSync();
        expect(Loa::count())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC'));
        forumWith([180 => [[$thread]]], [4002 => $thread]);
        runLoaSync();

        expect(Loa::sole()->forum_thread_id)->toBe(4002)->and(latestLoaRun()->status)->toBe(SyncRunStatus::Success);
    });

    it('fails with a clear message, and sends nothing, when no forum API key is set', function () {
        bindForum(['api_key' => null]);
        $client = forumWith([180 => [[]]]);

        expect(fn () => runLoaSync())->toThrow(ForumRequestException::class, 'SEVENCAV_FORUM_API_KEY');

        expect(latestLoaRun()->status)->toBe(SyncRunStatus::Failure)->and(latestLoaRun()->error)->toContain('SEVENCAV_FORUM_API_KEY');
        $client->assertNothingSent();
    });

    it('reads nothing, and succeeds, when no leave section is configured', function () {
        bindForum(['loa_nodes' => []]);
        $client = forumWith([]);

        runLoaSync();

        expect(latestLoaRun()->status)->toBe(SyncRunStatus::Success)->and(Loa::count())->toBe(0);
        $client->assertNothingSent();
    });

    it('records a thread the forum says is outside the configured sections as a failure to read, not a leave', function () {
        Trooper::factory()->create(['username' => 'Smith.J']);
        $listed = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00');
        $actuallyElsewhere = loaThread(4001, LOA_TITLE_SMITH, loaBody('loa-on-behalf'), '2026-10-05 08:00', 7);
        forumWith([180 => [[$listed]]], [4001 => $actuallyElsewhere]);

        runLoaSync();

        expect(Loa::count())->toBe(0)->and(latestLoaRun()->errored)->toBe(1)->and(latestLoaRun()->error)->toContain('not a configured LOA section');
    });
});
