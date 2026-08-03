<?php

use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Data\RosterType;
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
            ->and($profile->roster)->toBe(RosterType::Combat)
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
            cavProfilePayload(['mos' => '', 'roster' => 'ROSTER_TYPE_UNSPECIFIED']),
            $fetchedAt,
        );

        expect($profile->mos)->toBeNull()
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
        new CavRosterProfile(0, 3, $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a non-positive forum user id at construction', function () use ($fetchedAt) {
        new CavRosterProfile(1, 0, $fetchedAt);
    })->throws(CavRequestException::class);

    it('accepts an empty username, which identity never depends on', function () use ($fetchedAt) {
        // `username` is display-only (AC #6 matches on ids), and the API sends an unset string
        // as ''. Rejecting it would make a trooper with a blank roster username permanently
        // unenrichable — reported, misleadingly, as "holds no milpac".
        $profile = CavRosterProfile::fromPayload(
            1,
            cavProfilePayload(['user' => ['userId' => '3', 'username' => '']]),
            $fetchedAt,
        );

        expect($profile->username)->toBeNull()
            ->and($profile->forumUserId)->toBe(3);
    });

    it('rejects a fractional forum user id rather than truncating it to another trooper', function () use ($fetchedAt) {
        // `is_numeric` would admit '4.9' and the (int) cast would silently make it forum user 4.
        CavRosterProfile::fromPayload(1, cavProfilePayload(['user' => ['userId' => '4.9', 'username' => 'x']]), $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects an exponent-notation forum user id', function () use ($fetchedAt) {
        CavRosterProfile::fromPayload(1, cavProfilePayload(['user' => ['userId' => '1e3', 'username' => 'x']]), $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a non-string username without rejecting the record\'s identity', function () use ($fetchedAt) {
        $profile = CavRosterProfile::fromPayload(1, cavProfilePayload(['user' => ['userId' => '3', 'username' => 3]]), $fetchedAt);

        expect($profile->username)->toBeNull()->and($profile->forumUserId)->toBe(3);
    });
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

    it('ignores a non-numeric map key on another trooper\'s row', function () use ($fetchedAt) {
        // Skippable because the row is not ours. On our own row it throws instead — see below.
        $roster = ['profiles' => ['not-an-id' => cavProfilePayload()]];

        expect(CavRosterProfile::findInRoster($roster, 999, $fetchedAt))->toBeNull();
    });

    it('builds the roster endpoint only from a known roster type', function () {
        // The roster name is interpolated into the URL path, so the enum is what stops a
        // traversing or bogus path being constructible at all.
        expect(RosterType::serving())->toBe([RosterType::Combat, RosterType::Reserve, RosterType::Eloa])
            ->and(RosterType::tryFrom('ROSTER_TYPE_UNSPECIFIED'))->toBeNull()
            ->and(RosterType::Eloa->label())->toBe('ELOA');
    });

    it('rejects a response with no profiles map', function () use ($fetchedAt) {
        CavRosterProfile::findInRoster(['code' => 13, 'message' => 'internal'], 3, $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a profiles LIST, whose indices would masquerade as milpac ids', function () use ($fetchedAt) {
        // The dangerous shape: `is_array` passes, keys are 0,1,2… and look like valid milpac
        // ids. Taking one as a cav_id would persist a fabricated identity link AND strip it
        // from whoever legitimately holds that id — while the rendered rank still looked right,
        // because the forum-id match would hold.
        // Two entries, with the sought trooper at index 1: index 0 would be rejected anyway as a
        // non-positive id, so a one-entry list does not exercise the fabrication at all. Index 1
        // is a perfectly valid-looking milpac id, and taking it would link this trooper to
        // trooper 1's milpac and strip it from them.
        CavRosterProfile::findInRoster(
            ['profiles' => [cavProfilePayload(['user' => ['userId' => '99', 'username' => 'Other.P']]), cavProfilePayload()]],
            3,
            $fetchedAt,
        );
    })->throws(CavRequestException::class);

    it('accepts an empty profiles map (a roster nobody is on)', function () use ($fetchedAt) {
        // `[]` is both an empty list and an empty map; it must not trip the list rejection.
        expect(CavRosterProfile::findInRoster(['profiles' => []], 3, $fetchedAt))->toBeNull();
    });

    it('skips an entry whose value is not an object', function () use ($fetchedAt) {
        $roster = ['profiles' => ['77' => 'oops', '78' => cavProfilePayload()]];

        expect(CavRosterProfile::findInRoster($roster, 3, $fetchedAt)?->cavId)->toBe(78);
    });

    it('skips a fractional map key on another trooper\'s row', function () use ($fetchedAt) {
        // '77.9' passes is_numeric and (int)-casts to 77 — a different, valid milpac id.
        $roster = ['profiles' => ['77.9' => cavProfilePayload()]];

        expect(CavRosterProfile::findInRoster($roster, 999, $fetchedAt))->toBeNull();
    });

    it('refuses a fractional map key on the sought trooper\'s own row rather than truncating it', function () use ($fetchedAt) {
        // Truncating '77.9' to 77 would link this trooper to a different, valid milpac id — and
        // strip it from whoever legitimately holds it. Skipping quietly would be almost as bad:
        // the sweep would then report "no milpac" for a trooper the roster plainly carries.
        CavRosterProfile::findInRoster(['profiles' => ['77.9' => cavProfilePayload()]], 3, $fetchedAt);
    })->throws(CavRequestException::class);

    it('reports an unusable entry for the sought trooper instead of claiming they have no milpac', function () use ($fetchedAt) {
        // Map key 0 is not a valid milpac id, but the entry IS forum user 3's.
        $roster = ['profiles' => ['0' => ['user' => ['userId' => '3', 'username' => 'Jarvis.A']]]];

        CavRosterProfile::findInRoster($roster, 3, $fetchedAt);
    })->throws(CavRequestException::class);

    it('hands every skipped entry to the caller so a skip is never silent', function () use ($fetchedAt) {
        $skipped = [];
        $roster = ['profiles' => [
            '77' => 'not-an-object',
            '78' => ['user' => ['userId' => 'nonsense']],
            '79' => cavProfilePayload(),
        ]];

        CavRosterProfile::findInRoster($roster, 3, $fetchedAt, function ($key, $reason) use (&$skipped) {
            $skipped[$key] = $reason;
        });

        // PHP coerces numeric-string array keys to ints, so the keys arrive as 77/78 — which is
        // why the id parser accepts an int key as well as a decimal string.
        expect(array_keys($skipped))->toBe([77, 78])
            ->and($skipped[78])->toContain('user.userId');
    });
});

describe('request endpoints', function () {
    it('builds the milpac profile endpoint from the cav id', function () {
        expect((new FetchMilpacProfileRequest(42))->resolveEndpoint())
            ->toBe('/api/v1/milpacs/profile/id/42');
    });

    it('builds the lite roster endpoint from the roster type', function () {
        expect((new FetchRosterRequest(RosterType::Combat))->resolveEndpoint())
            ->toBe('/api/v1/roster/ROSTER_TYPE_COMBAT/lite');
    });
});
