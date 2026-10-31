<?php

use App\Commands\Auth\ForumPmGrant;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Integration\Forum\Requests\ExchangeCodeRequest;
use App\Integration\Forum\Requests\FetchProfileRequest;
use App\Integration\Forum\Requests\StartConversationRequest;
use App\Models\User;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// #565: the counseling PMs go out from the acting user's own forum account. The portal asks for the conversation scope
// only when someone is about to send one (never at sign-in), checks the token belongs to the signed-in user, and keeps
// it in their session only, encrypted, never in the database (AD-6, AD-23).

function mockPmConsent(int $forumUserId, int $tokenStatus = 200): void
{
    MockClient::destroyGlobal();
    MockClient::global([
        ExchangeCodeRequest::class => MockResponse::make(['access_token' => 'pm-tok', 'token_type' => 'Bearer', 'expires_in' => 3600], $tokenStatus),
        FetchProfileRequest::class => MockResponse::make(['me' => ['user_id' => $forumUserId, 'username' => 'Brandt.L', 'email' => null]]),
    ]);
}

beforeEach(function () {
    config()->set('services.sevencav', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/auth/callback',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ]);
    $this->user = User::factory()->serving()->create();
});

afterEach(fn () => MockClient::destroyGlobal());

it('asks the forum for the conversation scope on top of the sign-in scopes, only on request, and remembers where to return', function () {
    $response = $this->actingAs($this->user)->get(route('auth.forum-pm', ['return' => '/counseling/12']));

    $target = $response->headers->get('Location');
    expect($target)->toStartWith('https://7cav.us/oauth2/authorize?')
        ->and($target)->toContain('scope=user%3Aread+conversation%3Awrite')
        ->and($target)->toContain('state='.session('forum_pm_state'))
        ->and(session('forum_pm_return'))->toBe('/counseling/12');

    // Sign-in itself never asks for it.
    $this->post(route('logout'));
    expect($this->get('/auth/redirect')->headers->get('Location'))->not->toContain('conversation');
});

it('keeps a return address on this site only', function (string $return) {
    $this->actingAs($this->user)->get(route('auth.forum-pm', ['return' => $return]));

    expect(session('forum_pm_return'))->toBe('/home');
})->with(['https://evil.example/x', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)']);

it('keeps the signed-in user\'s token in their session, encrypted, and goes back where they came from', function () {
    mockPmConsent((int) $this->user->forum_user_id);
    $this->actingAs($this->user)->get(route('auth.forum-pm', ['return' => '/counseling/12']));
    $state = session('forum_pm_state');

    $this->actingAs($this->user)->get('/auth/callback?code=abc&state='.$state)->assertRedirect('/counseling/12')->assertSessionHas('status');

    $stored = session('forum_pm_grant');
    expect($stored)->toBeString()->not->toContain('pm-tok');
    $grant = ForumPmGrant::fromArray(decrypt($stored));
    expect($grant->accessToken)->toBe('pm-tok')
        ->and($grant->expiresAt->toIso8601String())->toBe(now()->addSeconds(3600)->toIso8601String());
    // Nothing in the database carries it.
    expect(json_encode(User::query()->find($this->user->id)->getAttributes()))->not->toContain('pm-tok');
});

it('refuses a token that belongs to another forum account', function () {
    mockPmConsent((int) $this->user->forum_user_id + 1);
    $this->actingAs($this->user)->get(route('auth.forum-pm', ['return' => '/home']));

    $this->actingAs($this->user)->get('/auth/callback?code=abc&state='.session('forum_pm_state'))
        ->assertRedirect('/home')->assertSessionHas('error');

    expect(session('forum_pm_grant'))->toBeNull();
});

it('keeps nothing on a cancelled consent, a mismatched state or a forum that refuses the code', function (string $query, int $tokenStatus) {
    mockPmConsent((int) $this->user->forum_user_id, $tokenStatus);
    $this->actingAs($this->user)->get(route('auth.forum-pm', ['return' => '/home']));
    $state = session('forum_pm_state');

    $this->actingAs($this->user)->get('/auth/callback?'.str_replace('{state}', $state, $query))->assertRedirect('/home')->assertSessionHas('error');

    expect(session('forum_pm_grant'))->toBeNull();
})->with([
    'cancelled' => ['error=access_denied&state={state}', 200],
    'wrong state' => ['code=abc&state=nope', 200],
    'no code' => ['state={state}', 200],
    'code refused' => ['code=abc&state={state}', 400],
]);

it('starts a conversation with the user\'s own token and answers its id', function () {
    MockClient::destroyGlobal();
    $mock = MockClient::global([StartConversationRequest::class => MockResponse::make(['conversation' => ['conversation_id' => 42]])]);

    $id = app(ForumApi::class)->startConversation('user-tok', [7, 9], 'After-Action: Letter of Reprimand', 'Body');

    expect($id)->toBe(42);
    $mock->assertSent(function ($request, $response) {
        $pending = $response->getPendingRequest();

        return $request instanceof StartConversationRequest
            && $pending->getUri()->getPath() === '/api/conversations/'
            && $pending->headers()->get('Authorization') === 'Bearer user-tok'
            && $pending->body()->all() === ['recipient_ids' => [7, 9], 'title' => 'After-Action: Letter of Reprimand', 'message' => 'Body'];
    });
});

it('fails a conversation the forum refuses or answers without an id, keeping the status', function () {
    MockClient::destroyGlobal();
    MockClient::global([StartConversationRequest::class => MockResponse::make(['errors' => [['code' => 'no_permission']]], 403)]);

    try {
        app(ForumApi::class)->startConversation('t', [1], 'x', 'y');
        $this->fail('expected a refusal');
    } catch (ForumRequestException $e) {
        expect($e->httpStatus)->toBe(403)->and($e->getMessage())->toContain('no_permission');
    }

    MockClient::destroyGlobal();
    MockClient::global([StartConversationRequest::class => MockResponse::make(['conversation' => []])]);
    expect(fn () => app(ForumApi::class)->startConversation('t', [1], 'x', 'y'))->toThrow(ForumRequestException::class, 'without a conversation id');
});
