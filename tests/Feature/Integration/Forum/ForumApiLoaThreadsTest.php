<?php

use App\Integration\Forum\Data\ForumThread;
use App\Integration\Forum\Data\ForumThreadPage;
use App\Integration\Forum\Data\ForumThreadSummary;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Integration\Forum\Requests\ListForumThreadsRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Story 4.1: the forum's read path for leave threads, faked at the AD-3 seam by URL. The payload shapes
 * follow the XenForo 2 REST API as the forum documents it elsewhere; nothing here has been run against
 * the live forum yet.
 */
const LOA_LIST_URL = '7cav.us/api/forums/180/threads*';
const LOA_THREAD_URL = '7cav.us/api/threads/4001';

function loaForum(array $overrides = []): ForumApi
{
    return new ForumApi([
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://portal.test/cb',
        'base_url' => 'https://7cav.us', 'authorize_url' => 'https://7cav.us/oauth2/authorize', 'scopes' => ['user:read'],
        'api_key' => 'forum-key-123',
        'loa_nodes' => [180 => 'loa', 400 => 'loa', 341 => 'eloa'],
        ...$overrides,
    ]);
}

function loaThreadJson(array $overrides = []): array
{
    return [...[
        'thread_id' => 4001, 'node_id' => 180, 'title' => '[LOA Request] - SPC.Perry.J | Oct 10 - Oct 20',
        'post_date' => 1_791_000_000, 'discussion_state' => 'visible', 'view_url' => 'https://7cav.us/threads/loa.4001/',
    ], ...$overrides];
}

beforeEach(fn () => MockClient::destroyGlobal());

describe('listing a leave section', function () {
    it('returns typed thread summaries and where the page sits', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make([
            'threads' => [loaThreadJson(), loaThreadJson(['thread_id' => 4002, 'title' => 'Another'])],
            'pagination' => ['current_page' => 1, 'last_page' => 3, 'per_page' => 20, 'shown' => 2, 'total' => 41],
        ])]);

        $page = loaForum()->listLoaThreads(180);

        expect($page)->toBeInstanceOf(ForumThreadPage::class)
            ->and($page->threads)->toHaveCount(2)
            ->and($page->threads[0])->toBeInstanceOf(ForumThreadSummary::class)
            ->and($page->threads[0]->threadId)->toBe(4001)
            ->and($page->threads[0]->nodeId)->toBe(180)
            ->and($page->threads[0]->url)->toBe('https://7cav.us/threads/loa.4001/')
            ->and($page->threads[0]->postedAt->getTimestamp())->toBe(1_791_000_000)
            ->and($page->page)->toBe(1)
            ->and($page->lastPage)->toBe(3)
            ->and($page->hasMore())->toBeTrue();
    });

    it('asks for the page it was given, newest thread first, and reports the last page as the end', function () {
        $client = MockClient::global([LOA_LIST_URL => MockResponse::make([
            'threads' => [], 'pagination' => ['current_page' => 3, 'last_page' => 3],
        ])]);

        $page = loaForum()->listLoaThreads(180, 3);

        $client->assertSent(fn ($request, $response) => $request instanceof ListForumThreadsRequest && $response->getPendingRequest()->query()->all() === ['page' => 3, 'order' => 'post_date', 'direction' => 'desc']);
        expect($page->hasMore())->toBeFalse()->and($page->threads)->toBe([]);
    });

    it('sends the forum API key as the credential', function () {
        $client = MockClient::global([LOA_LIST_URL => MockResponse::make(['threads' => []])]);

        loaForum()->listLoaThreads(180);

        $client->assertSent(fn ($request, $response) => $response->getPendingRequest()->headers()->get('XF-Api-Key') === 'forum-key-123');
    });

    it('lists the ELOA section too, which has its own place on the forum', function () {
        MockClient::global(['7cav.us/api/forums/341/threads*' => MockResponse::make(['threads' => [loaThreadJson(['thread_id' => 5001, 'node_id' => 341, 'title' => '[ELOA Return] - 1SG.Leifried.R'])]])]);

        $thread = loaForum()->listLoaThreads(341)->threads[0];

        expect($thread->nodeId)->toBe(341)
            ->and($thread->title)->toBe('[ELOA Return] - 1SG.Leifried.R')
            ->and(loaForum()->loaNodes()[341])->toBe('eloa');
    });

    it('leaves pinned threads out, since the forum returns them separately and they are notices', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make([
            'threads' => [loaThreadJson(['thread_id' => 4001])],
            'sticky' => [loaThreadJson(['thread_id' => 1, 'title' => 'How to file an LOA', 'sticky' => true])],
            'pagination' => ['current_page' => 1, 'last_page' => 1],
        ])]);

        expect(array_map(fn ($thread) => $thread->threadId, loaForum()->listLoaThreads(180)->threads))->toBe([4001]);
    });

    it('treats a thread that is not visible as such', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make(['threads' => [loaThreadJson(['discussion_state' => 'deleted'])]])]);

        expect(loaForum()->listLoaThreads(180)->threads[0]->isVisible())->toBeFalse();
    });

    it('refuses a section that is not a configured leave section, and sends nothing', function () {
        $client = MockClient::global([LOA_LIST_URL => MockResponse::make(['threads' => []])]);

        expect(fn () => loaForum()->listLoaThreads(999))->toThrow(ForumRequestException::class, 'not a configured LOA section');

        $client->assertNothingSent();
    });

    it('reads nothing while no leave section is configured', function () {
        $client = MockClient::global([LOA_LIST_URL => MockResponse::make(['threads' => []])]);

        expect(fn () => loaForum(['loa_nodes' => []])->listLoaThreads(180))->toThrow(ForumRequestException::class);

        $client->assertNothingSent();
    });
});

describe('fetching a leave thread', function () {
    it('returns the thread with the body of its first post', function () {
        MockClient::global([LOA_THREAD_URL => MockResponse::make([
            'thread' => loaThreadJson(),
            'first_post' => ['post_id' => 9, 'message' => "[B]Username[/B]: Perry.J\nStart Date\nOct 10, 2026"],
        ])]);

        $thread = loaForum()->fetchLoaThread(4001);

        expect($thread)->toBeInstanceOf(ForumThread::class)
            ->and($thread->summary->threadId)->toBe(4001)
            ->and($thread->firstPostBody)->toContain('Start Date');
    });

    it('asks the forum to include the first post, which it leaves out otherwise', function () {
        $client = MockClient::global([LOA_THREAD_URL => MockResponse::make(['thread' => loaThreadJson(), 'first_post' => ['message' => 'x']])]);

        loaForum()->fetchLoaThread(4001);

        $client->assertSent(fn ($request, $response) => $response->getPendingRequest()->query()->all() === ['with_first_post' => 1]);
    });

    it('does not accept a thread that came back without its first post', function () {
        MockClient::global([LOA_THREAD_URL => MockResponse::make(['thread' => loaThreadJson(), 'posts' => [['message' => 'a later page of posts']]])]);

        expect(fn () => loaForum()->fetchLoaThread(4001))->toThrow(ForumRequestException::class, 'missing a field');
    });

    it('sends the forum API key as the credential', function () {
        $client = MockClient::global([LOA_THREAD_URL => MockResponse::make(['thread' => loaThreadJson(), 'first_post' => ['message' => 'x']])]);

        loaForum()->fetchLoaThread(4001);

        $client->assertSent(fn ($request, $response) => $response->getPendingRequest()->headers()->get('XF-Api-Key') === 'forum-key-123');
    });

    it('refuses a thread outside the configured leave sections, even though the key could read it', function () {
        MockClient::global([LOA_THREAD_URL => MockResponse::make([
            'thread' => loaThreadJson(['node_id' => 7]), 'first_post' => ['message' => 'private staff thread'],
        ])]);

        expect(fn () => loaForum()->fetchLoaThread(4001))->toThrow(ForumRequestException::class, 'Forum section 7 is not a configured LOA section');
    });
});

describe('when the forum cannot be read', function () {
    it('refuses to send anything without an API key', function (?string $key) {
        $client = MockClient::global([LOA_LIST_URL => MockResponse::make(['threads' => []]), LOA_THREAD_URL => MockResponse::make([])]);

        expect(fn () => loaForum(['api_key' => $key])->listLoaThreads(180))->toThrow(ForumRequestException::class, 'SEVENCAV_FORUM_API_KEY');
        expect(fn () => loaForum(['api_key' => $key])->fetchLoaThread(4001))->toThrow(ForumRequestException::class, 'SEVENCAV_FORUM_API_KEY');

        $client->assertNothingSent();
    })->with([null, '']);

    it('turns an HTTP error into a ForumRequestException naming the status', function (int $status) {
        MockClient::global([LOA_LIST_URL => MockResponse::make(['errors' => []], $status), LOA_THREAD_URL => MockResponse::make([], $status)]);

        expect(fn () => loaForum()->listLoaThreads(180))->toThrow(ForumRequestException::class, "HTTP {$status}");
        expect(fn () => loaForum()->fetchLoaThread(4001))->toThrow(ForumRequestException::class, "HTTP {$status}");
    })->with([401, 403, 404, 500, 503]);

    it('names the error code the forum sends, so a missing or wrong key is obvious', function (string $code, int $status) {
        MockClient::global([LOA_LIST_URL => MockResponse::make(['errors' => [['code' => $code, 'message' => 'free text the portal never passes on', 'params' => []]]], $status)]);

        try {
            loaForum()->listLoaThreads(180);
            test()->fail('Expected a ForumRequestException.');
        } catch (ForumRequestException $e) {
            expect($e->getMessage())->toContain("HTTP {$status}, {$code}")->not->toContain('free text');
        }
    })->with([
        'no key' => ['no_api_key_in_request', 400],
        'unknown key' => ['api_key_not_found', 400],
        'not permitted' => ['no_permission', 403],
    ]);

    it('leaves the code out when the error body is not in the forum error format', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make('<html>bad gateway</html>', 502)]);

        expect(fn () => loaForum()->listLoaThreads(180))->toThrow(ForumRequestException::class, '(HTTP 502).');
    });

    it('turns an unreachable forum into a ForumRequestException', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make()->throw(fn ($pendingRequest) => new FatalRequestException(new Exception('timeout'), $pendingRequest))]);

        expect(fn () => loaForum()->listLoaThreads(180))->toThrow(ForumRequestException::class, 'could not be reached');
    });

    it('turns a body that is not JSON into a ForumRequestException', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make('<html>maintenance</html>')]);

        expect(fn () => loaForum()->listLoaThreads(180))->toThrow(ForumRequestException::class);
    });

    it('rejects a thread list missing a field the portal needs', function (array $thread) {
        MockClient::global([LOA_LIST_URL => MockResponse::make(['threads' => [$thread]])]);

        expect(fn () => loaForum()->listLoaThreads(180))->toThrow(ForumRequestException::class, 'missing a field');
    })->with([
        'no thread id' => [['node_id' => 180, 'title' => 't', 'post_date' => 1]],
        'no title' => [['thread_id' => 1, 'node_id' => 180, 'post_date' => 1]],
        'no post date' => [['thread_id' => 1, 'node_id' => 180, 'title' => 't']],
        'zero thread id' => [['thread_id' => 0, 'node_id' => 180, 'title' => 't', 'post_date' => 1, 'discussion_state' => 'visible']],
        'no discussion state' => [['thread_id' => 1, 'node_id' => 180, 'title' => 't', 'post_date' => 1]],
    ]);

    it('rejects a list with no threads key, and a thread with no first post', function () {
        MockClient::global([LOA_LIST_URL => MockResponse::make(['something' => 'else']), LOA_THREAD_URL => MockResponse::make(['thread' => loaThreadJson()])]);

        expect(fn () => loaForum()->listLoaThreads(180))->toThrow(ForumRequestException::class, 'missing a field');
        expect(fn () => loaForum()->fetchLoaThread(4001))->toThrow(ForumRequestException::class, 'missing a field');
    });
});

it('lists the configured leave sections and the kind each one holds', function () {
    expect(loaForum()->loaNodes())->toBe([180 => 'loa', 400 => 'loa', 341 => 'eloa'])
        ->and(loaForum(['loa_nodes' => []])->loaNodes())->toBe([]);
});

it('keeps the thread DTOs immutable', function () {
    $summary = ForumThreadSummary::fromArray(loaThreadJson());

    expect(fn () => $summary->title = 'x')->toThrow(Error::class);
});

function pafThreadJson(array $overrides = []): array
{
    return ['thread' => [...[
        'thread_id' => 106503, 'node_id' => 365, 'title' => 'PAF - Lane.M', 'discussion_state' => 'visible',
        'post_date' => 1_790_000_000,
    ], ...$overrides]];
}

describe('reading when a PAF thread was filed', function () {
    it('returns the thread\'s own creation date and asks for nothing else', function () {
        $client = MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make(pafThreadJson())]);

        $filed = loaForum(['paf_node' => 365])->fetchPafThreadPostedAt(106503);

        expect($filed->getTimestamp())->toBe(1_790_000_000)->and($filed->utcOffset())->toBe(0);
        $client->assertSent(fn ($request, $response) => $response->getPendingRequest()->query()->all() === []
            && $response->getPendingRequest()->headers()->get('XF-Api-Key') === 'forum-key-123');
    });

    it('refuses, and sends nothing, when no PAF section is configured or there is no key', function (array $config, string $message) {
        $client = MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make(pafThreadJson())]);

        expect(fn () => loaForum($config)->fetchPafThreadPostedAt(106503))->toThrow(ForumRequestException::class, $message);

        $client->assertNothingSent();
    })->with([
        'no section' => [['paf_node' => null], 'SEVENCAV_FORUM_PAF_NODE'],
        'no key' => [['paf_node' => 365, 'api_key' => null], 'SEVENCAV_FORUM_API_KEY'],
    ]);

    it('refuses a thread from another section, permanently, so the key cannot read the rest of the forum through it', function () {
        MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make(pafThreadJson(['node_id' => 12]))]);

        try {
            loaForum(['paf_node' => 365])->fetchPafThreadPostedAt(106503);
            test()->fail('Expected a ForumRequestException.');
        } catch (ForumRequestException $e) {
            expect($e->getMessage())->toContain('forum section 12')->and($e->isPermanent())->toBeTrue();
        }
    });

    it('refuses a thread that is not visible, permanently', function () {
        MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make(pafThreadJson(['discussion_state' => 'deleted']))]);

        try {
            loaForum(['paf_node' => 365])->fetchPafThreadPostedAt(106503);
            test()->fail('Expected a ForumRequestException.');
        } catch (ForumRequestException $e) {
            expect($e->isPermanent())->toBeTrue();
        }
    });

    it('tells a permanent refusal from a temporary failure by the status the forum answered with', function (int $status, bool $permanent) {
        MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make([], $status)]);

        try {
            loaForum(['paf_node' => 365])->fetchPafThreadPostedAt(106503);
            test()->fail('Expected a ForumRequestException.');
        } catch (ForumRequestException $e) {
            expect($e->httpStatus)->toBe($status)->and($e->isPermanent())->toBe($permanent);
        }
    })->with([[403, true], [404, true], [410, true], [429, false], [500, false], [502, false], [503, false]]);

    it('treats an unreachable forum as temporary', function () {
        MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make()->throw(fn ($pendingRequest) => new FatalRequestException(new Exception('timeout'), $pendingRequest))]);

        try {
            loaForum(['paf_node' => 365])->fetchPafThreadPostedAt(106503);
            test()->fail('Expected a ForumRequestException.');
        } catch (ForumRequestException $e) {
            expect($e->isPermanent())->toBeFalse()->and($e->httpStatus)->toBeNull();
        }
    });

    it('rejects a payload with no thread in it', function () {
        MockClient::global(['7cav.us/api/threads/106503' => MockResponse::make(['something' => 'else'])]);

        expect(fn () => loaForum(['paf_node' => 365])->fetchPafThreadPostedAt(106503))->toThrow(ForumRequestException::class, 'missing a field');
    });

    it('reads the configured PAF section as a number, and nothing else as one', function (mixed $value, ?int $expected) {
        expect(loaForum(['paf_node' => $value])->pafNode())->toBe($expected);
    })->with([[365, 365], [null, null], [0, null], [-4, null], ['365', null]]);

    it('builds a thread address from the forum base when the forum sent none', function () {
        expect(loaForum()->threadUrl(4001))->toBe('https://7cav.us/threads/4001/');
    });
});
