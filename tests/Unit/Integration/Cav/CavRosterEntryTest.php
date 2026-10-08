<?php

use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavRosterEntry;
use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Data\RosterType;
use Carbon\CarbonImmutable;

// Pure, app-free unit tests (Pest binds Laravel/DB only to the Feature suite).

$fetchedAt = new CarbonImmutable('2026-08-03 09:14:00');

/**
 * A `LiteProfile` roster entry as api.7cav.us really sends it (verified live, story 2.1). No
 * `forumUserId` requirement here, unlike {@see CavRosterProfile}: a
 * roster row need not resolve to a portal user to still be a valid trooper record.
 */
function rosterEntryPayload(array $overrides = []): array
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

describe('CavRosterEntry::fromPayload', function () use ($fetchedAt) {
    it('reads the roster facts, taking the cav id from the caller not the body', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(), $fetchedAt);

        expect($trooper->cavId)->toBe(1)
            ->and($trooper->rankShort)->toBe('MG')
            ->and($trooper->rankFull)->toBe('Major General')
            ->and($trooper->billet)->toBe('Regimental Technical Aide')
            ->and($trooper->positionId)->toBe(773)
            ->and($trooper->mos)->toBe('255N')
            ->and($trooper->tis)->toEqual(new CarbonImmutable('2012-05-18'))
            ->and($trooper->tig)->toEqual(new CarbonImmutable('2025-05-16'))
            ->and($trooper->status)->toBe(RosterType::Combat)
            ->and($trooper->fetchedAt)->toEqual($fetchedAt);
    });

    it('does not require a resolvable forum user id, unlike CavRosterProfile', function () use ($fetchedAt) {
        // A trooper can be on the roster with no forum account linked yet; this DTO must not
        // punish that with a thrown exception the way the identity-focused DTO does.
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => null]), $fetchedAt);

        expect($trooper->cavId)->toBe(1);
    });

    it('normalizes the API\'s "unset object is null" convention to null fields', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['rank' => null, 'primary' => null]), $fetchedAt);

        expect($trooper->rankShort)->toBeNull()
            ->and($trooper->rankFull)->toBeNull()
            ->and($trooper->billet)->toBeNull()
            ->and($trooper->positionId)->toBeNull();
    });

    it('leaves positionId null when the primary position carries no positionId', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['primary' => ['positionTitle' => 'Rifleman', 'positionId' => '']]), $fetchedAt);

        expect($trooper->positionId)->toBeNull()
            ->and($trooper->billet)->toBe('Rifleman');
    });

    it('leaves positionId null on a non-numeric positionId rather than throwing', function () use ($fetchedAt) {
        // A drift here must cost this trooper their section mapping (2.5), not their whole record.
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['primary' => ['positionTitle' => 'Rifleman', 'positionId' => 'not-a-number']]), $fetchedAt);

        expect($trooper->positionId)->toBeNull();
    });

    it('reads positionId when the API sends it as a bare int rather than a numeric string', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['primary' => ['positionTitle' => 'Rifleman', 'positionId' => 773]]), $fetchedAt);

        expect($trooper->positionId)->toBe(773);
    });

    it('normalizes the API\'s "unset string is empty" convention to null fields', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['mos' => '']), $fetchedAt);

        expect($trooper->mos)->toBeNull();
    });

    it('maps the API\'s explicit unspecified roster to a null status rather than a magic string', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['roster' => 'ROSTER_TYPE_UNSPECIFIED']), $fetchedAt);

        expect($trooper->status)->toBeNull();
    });

    it('leaves tis/tig null when the API sends no join or promotion date', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['joinDate' => '', 'promotionDate' => '']), $fetchedAt);

        expect($trooper->tis)->toBeNull()->and($trooper->tig)->toBeNull();
    });

    it('leaves tis null on an unparseable join date rather than throwing', function () use ($fetchedAt) {
        // A date field drifting is not identity — it must cost this trooper their TIS badge,
        // not their whole roster record.
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['joinDate' => 'not-a-date']), $fetchedAt);

        expect($trooper->tis)->toBeNull();
    });

    it('rejects an error body returned with a 200 (the API\'s `code`/`message` envelope)', function () use ($fetchedAt) {
        CavRosterEntry::fromPayload(1, ['code' => 5, 'message' => 'no profile found', 'details' => []], $fetchedAt);
    })->throws(CavRequestException::class);

    it('rejects a non-positive cav id at construction', function () use ($fetchedAt) {
        new CavRosterEntry(cavId: 0, fetchedAt: $fetchedAt);
    })->throws(CavRequestException::class);
});

describe('username (2.6)', function () use ($fetchedAt) {
    it('reads the milpac username for display', function () use ($fetchedAt) {
        $entry = CavRosterEntry::fromPayload(3, rosterEntryPayload(), $fetchedAt);

        expect($entry->username)->toBe('Jarvis.A');
    });

    it('leaves the username null for a trooper with no forum account linked', function () use ($fetchedAt) {
        $entry = CavRosterEntry::fromPayload(3, rosterEntryPayload(['user' => null]), $fetchedAt);

        expect($entry->username)->toBeNull()
            ->and($entry->cavId)->toBe(3);
    });

    it('treats an empty username as null rather than as a name', function () use ($fetchedAt) {
        $entry = CavRosterEntry::fromPayload(3, rosterEntryPayload(['user' => ['userId' => '3', 'username' => '']]), $fetchedAt);

        expect($entry->username)->toBeNull();
    });
});
