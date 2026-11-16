<?php

use App\Integration\Forum\Data\ForumPostPage;
use App\Integration\Forum\Data\ForumProfile;
use App\Integration\Forum\Data\ForumToken;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;

// Pure, app-free unit tests for the Forum seam's parsing + URL-building logic.
// No Laravel app or DB is booted (Pest binds those only to the Feature suite).

describe('ForumToken::fromArray', function () {
    it('reads the access token from a well-formed payload', function () {
        $token = ForumToken::fromArray(['access_token' => 'tok-abc', 'token_type' => 'Bearer']);

        expect($token->accessToken)->toBe('tok-abc');
    });

    it('rejects a payload with no access token', function () {
        ForumToken::fromArray(['token_type' => 'Bearer']);
    })->throws(ForumRequestException::class);

    it('rejects an empty access token at construction', function () {
        new ForumToken('');
    })->throws(ForumRequestException::class);
});

describe('ForumProfile::fromArray', function () {
    it('reads identity from the XenForo `me` envelope', function () {
        $profile = ForumProfile::fromArray(['me' => ['user_id' => 4, 'username' => 'Gryspeert.J', 'email' => 'j@7cav.us']]);

        expect($profile->forumUserId)->toBe(4)
            ->and($profile->username)->toBe('Gryspeert.J')
            ->and($profile->email)->toBe('j@7cav.us');
    });

    it('tolerates a flat (un-enveloped) shape', function () {
        $profile = ForumProfile::fromArray(['user_id' => 7, 'username' => 'Doe.J']);

        expect($profile->forumUserId)->toBe(7)
            ->and($profile->email)->toBeNull();
    });

    it('normalizes a missing or empty email to null', function () {
        $profile = ForumProfile::fromArray(['me' => ['user_id' => 4, 'username' => 'x', 'email' => '']]);

        expect($profile->email)->toBeNull();
    });

    it('rejects a payload missing the user id', function () {
        ForumProfile::fromArray(['me' => ['username' => 'NoId']]);
    })->throws(ForumRequestException::class);

    it('rejects a non-numeric user id', function () {
        ForumProfile::fromArray(['me' => ['user_id' => 'abc', 'username' => 'x']]);
    })->throws(ForumRequestException::class);

    it('rejects a non-positive forum user id at construction', function () {
        new ForumProfile(0, 'x');
    })->throws(ForumRequestException::class);
});

describe('ForumApi::authorizeUrl', function () {
    $config = [
        'client_id' => 'client-123',
        'client_secret' => 'secret',
        'redirect_uri' => 'https://portal.test/auth/callback',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ];

    it('builds an authorization-code URL carrying the read-only scope and state', function () use ($config) {
        $url = (new ForumApi($config))->authorizeUrl('nonce-xyz');

        expect($url)->toStartWith('https://7cav.us/oauth2/authorize?')
            ->and($url)->toContain('response_type=code')
            ->and($url)->toContain('client_id=client-123')
            ->and($url)->toContain('scope=user%3Aread')
            ->and($url)->toContain('state=nonce-xyz');
    });

    it('space-joins multiple scopes', function () use ($config) {
        $url = (new ForumApi([...$config, 'scopes' => ['user:read', 'profile:read']]))->authorizeUrl('s');

        // http_build_query encodes the space between scopes as '+'.
        expect($url)->toContain('scope=user%3Aread+profile%3Aread');
    });
});

describe('ForumPostPage::fromArray', function () {
    it('reads each post with its author, and flags the opening post', function () {
        $page = ForumPostPage::fromArray([
            'posts' => [
                ['post_id' => 10, 'user_id' => 4, 'username' => 'Vasquez.R', 'message' => 'Roll call', 'post_date' => 1_791_000_000, 'message_state' => 'visible', 'position' => 0, 'is_first_post' => true],
                ['post_id' => 11, 'user_id' => 7, 'username' => 'Doe.J', 'message' => 'Will attend', 'post_date' => 1_791_000_100, 'message_state' => 'deleted', 'position' => 1, 'view_url' => 'https://7cav.us/posts/11/'],
            ],
            'pagination' => ['current_page' => 1, 'last_page' => 2],
        ]);

        expect($page->posts)->toHaveCount(2)
            ->and($page->posts[0]->isFirstPost)->toBeTrue()
            ->and($page->posts[1]->isFirstPost)->toBeFalse()
            ->and($page->posts[1]->userId)->toBe(7)
            ->and($page->posts[1]->username)->toBe('Doe.J')
            ->and($page->posts[1]->isVisible())->toBeFalse()
            ->and($page->posts[1]->url)->toBe('https://7cav.us/posts/11/')
            ->and($page->posts[1]->postedAt->getTimestamp())->toBe(1_791_000_100)
            ->and($page->hasMore())->toBeTrue();
    });

    it('rejects a page without its pagination', function () {
        ForumPostPage::fromArray(['posts' => []]);
    })->throws(ForumRequestException::class);

    it('rejects a post whose state is not given', function () {
        ForumPostPage::fromArray(['posts' => [['post_id' => 1, 'user_id' => 1, 'username' => 'A', 'message' => 'x', 'post_date' => 1]], 'pagination' => []]);
    })->throws(ForumRequestException::class);
});

describe('ForumApi::threadIdFromUrl', function () {
    $api = fn () => new ForumApi([
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://portal.test/cb',
        'base_url' => 'https://7cav.us', 'authorize_url' => 'https://7cav.us/oauth2/authorize', 'scopes' => ['user:read'],
    ]);

    it('reads the thread id from a thread link, with or without its title, page or anchor', function () use ($api) {
        expect($api()->threadIdFromUrl('https://7cav.us/threads/2-1-a-1-7-roll-call-03oct26.12345/'))->toBe(12345)
            ->and($api()->threadIdFromUrl('https://7cav.us/threads/12345/'))->toBe(12345)
            ->and($api()->threadIdFromUrl('https://7cav.us/threads/v2.0-roll-call.678/page-2#post-901'))->toBe(678)
            ->and($api()->threadIdFromUrl('https://7CAV.us/threads/roll-call.9'))->toBe(9);
    });

    it('reads no thread from another host, a post link or a section', function () use ($api) {
        expect($api()->threadIdFromUrl('https://example.com/threads/roll-call.12345/'))->toBeNull()
            ->and($api()->threadIdFromUrl('https://7cav.us/posts/901/'))->toBeNull()
            ->and($api()->threadIdFromUrl('https://7cav.us/forums/roll-calls.80/'))->toBeNull()
            ->and($api()->threadIdFromUrl('not a link'))->toBeNull();
    });
});
