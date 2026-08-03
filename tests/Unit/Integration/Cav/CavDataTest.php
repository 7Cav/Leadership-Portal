<?php

use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Requests\FetchMilpacProfileRequest;
use App\Integration\Cav\Requests\FetchRosterRequest;
use Carbon\CarbonImmutable;

// Pure, app-free unit tests for the Cav seam's parsing + endpoint building.
// No Laravel app or DB is booted (Pest binds those only to the Feature suite).

$fetchedAt = new CarbonImmutable('2026-08-03 09:14:00');

/**
 * The API's `Profile` shape, verified live against api.7cav.us. Note `user.userId` is the
 * *forum* user id — the milpac relation id is not in the body at all, it is the key of the
 * roster map (and the `profile/id/{…}` path value).
 */
function cavProfilePayload(array $overrides = []): array
{
    return [...[
        'user' => ['userId' => '3', 'username' => 'Jarvis.A'],
        'rank' => [
            'rankShort' => 'MG',
            'rankFull' => 'Major General',
            'rankImageUrl' => 'https://7cav.us/data/roster_ranks/0/4.jpg',
            'rankId' => '4',
        ],
        'realName' => 'Adam Jarvis',
        'uniformUrl' => 'https://7cav.us/data/roster_uniforms/0/1.jpg',
        'roster' => 'ROSTER_TYPE_COMBAT',
        'primary' => ['positionTitle' => 'Regimental Technical Aide', 'positionId' => '773'],
        'secondaries' => [],
        'joinDate' => '2012-05-18',
        'promotionDate' => '2025-05-16',
        'discordId' => '104461066662060032',
        'lastForumPostDate' => '2024-07-05 06:27:07',
        'mos' => '255N',
        'consoleGamertag' => '',
    ], ...$overrides];
}

describe('CavRosterProfile::fromPayload', function () use ($fetchedAt) {
    it('reads the roster facts, taking the cav id from the caller not the body', function () use ($fetchedAt) {
        $profile = CavRosterProfile::fromPayload(1, cavProfilePayload(), $fetchedAt);

        // cavId is the milpac relation id (the path/map key); forumUserId is the body's userId.
        expect($profile->cavId)->toBe(1)
            ->and($profile->forumUserId)->toBe(3)
            ->and($profile->username)->toBe('Jarvis.A')
            ->and($profile->rankShort)->toBe('MG')
            ->and($profile->rankFull)->toBe('Major General')
            ->and($profile->billet)->toBe('Regimental Technical Aide')
            ->and($profile->mos)->toBe('255N')
            ->and($profile->roster)->toBe('ROSTER_TYPE_COMBAT')
            ->and($profile->realName)->toBe('Adam Jarvis')
            ->and($profile->fetchedAt)->toEqual($fetchedAt);
    });

    it('normalizes the API\'s "unset object is null" convention to null fields', function () use ($fetchedAt) {
        $profile = CavRosterProfile::fromPayload(
            1,
            cavProfilePayload(['rank' => null, 'primary' => null]),
            $fetchedAt,
        );

        expect($profile->rankShort)->toBeNull()
            ->and($profile->rankFull)->toBeNull()
            ->and($profile->billet)->toBeNull();
    });

    it('normalizes the API\'s "unset string is empty" convention to null fields', function () use ($fetchedAt) {
        $profile = CavRosterProfile::fromPayload(
            1,
            cavProfilePayload(['mos' => '', 'realName' => '', 'roster' => 'ROSTER_TYPE_UNSPECIFIED']),
            $fetchedAt,
        );

        expect($profile->mos)->toBeNull()
            ->and($profile->realName)->toBeNull()
            ->and($profile->roster)->toBeNull();
    });

    it('rejects a payload with no user envelope', function () use ($fetchedAt) {
        CavRosterProfile::fromPayload(1, cavProfilePayload(['user' => null]), $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a non-numeric forum user id', function () use ($fetchedAt) {
        CavRosterProfile::fromPayload(1, cavProfilePayload(['user' => ['userId' => 'abc', 'username' => 'x']]), $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects an error body returned with a 200 (the API\'s `code`/`message` envelope)', function () use ($fetchedAt) {
        CavRosterProfile::fromPayload(1, ['code' => 5, 'message' => 'no profile found', 'details' => []], $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a non-positive cav id at construction', function () use ($fetchedAt) {
        new CavRosterProfile(0, 3, 'Jarvis.A', $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a non-positive forum user id at construction', function () use ($fetchedAt) {
        new CavRosterProfile(1, 0, 'Jarvis.A', $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects an empty username at construction', function () use ($fetchedAt) {
        new CavRosterProfile(1, 3, '', $fetchedAt);
    })->throws(CavRequestException::class);
});

describe('CavRosterProfile::findInRoster', function () use ($fetchedAt) {
    $rosterPayload = [
        'profiles' => [
            '1' => cavProfilePayload(),
            '10' => cavProfilePayload(['user' => ['userId' => '25', 'username' => 'LaCombe.M']]),
        ],
    ];

    it('matches on the forum user id and returns the map key as the cav id', function () use ($rosterPayload, $fetchedAt) {
        $profile = CavRosterProfile::findInRoster($rosterPayload, 25, $fetchedAt);

        expect($profile)->not->toBeNull()
            ->and($profile->cavId)->toBe(10)
            ->and($profile->forumUserId)->toBe(25)
            ->and($profile->username)->toBe('LaCombe.M');
    });

    it('returns null when no entry carries that forum user id', function () use ($rosterPayload, $fetchedAt) {
        expect(CavRosterProfile::findInRoster($rosterPayload, 999, $fetchedAt))->toBeNull();
    });

    it('never falls back to matching a username', function () use ($fetchedAt) {
        // Same username, different forum id: the roster sweep must not claim this record.
        $roster = ['profiles' => ['1' => cavProfilePayload(['user' => ['userId' => '4', 'username' => 'Jarvis.A']])]];

        expect(CavRosterProfile::findInRoster($roster, 3, $fetchedAt))->toBeNull();
    });

    it('skips malformed entries rather than failing the whole sweep', function () use ($fetchedAt) {
        $roster = ['profiles' => [
            '1' => ['user' => null],
            '2' => cavProfilePayload(['user' => ['userId' => '3', 'username' => 'Jarvis.A']]),
        ]];

        expect(CavRosterProfile::findInRoster($roster, 3, $fetchedAt)?->cavId)->toBe(2);
    });

    it('ignores a non-numeric map key, which cannot be a milpac id', function () use ($fetchedAt) {
        $roster = ['profiles' => ['not-an-id' => cavProfilePayload()]];

        expect(CavRosterProfile::findInRoster($roster, 3, $fetchedAt))->toBeNull();
    });

    it('rejects a response with no profiles map', function () use ($fetchedAt) {
        CavRosterProfile::findInRoster(['code' => 13, 'message' => 'internal'], 3, $fetchedAt);
    })->throws(CavRequestException::class);
});

describe('request endpoints', function () {
    it('builds the milpac profile endpoint from the cav id', function () {
        expect((new FetchMilpacProfileRequest(42))->resolveEndpoint())
            ->toBe('/api/v1/milpacs/profile/id/42');
    });

    it('builds the lite roster endpoint from the roster type', function () {
        expect((new FetchRosterRequest('ROSTER_TYPE_COMBAT'))->resolveEndpoint())
            ->toBe('/api/v1/roster/ROSTER_TYPE_COMBAT/lite');
    });
});
