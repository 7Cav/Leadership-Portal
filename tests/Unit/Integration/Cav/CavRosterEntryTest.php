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
            ->and($trooper->username)->toBe('Jarvis.A')
            ->and($trooper->forumUserId)->toBe(3)
            ->and($trooper->billet)->toBe('Regimental Technical Aide')
            ->and($trooper->positionId)->toBe(773)
            ->and($trooper->mos)->toBe('255N')
            ->and($trooper->tis)->toEqual(new CarbonImmutable('2012-05-18'))
            ->and($trooper->tig)->toEqual(new CarbonImmutable('2025-05-16'))
            ->and($trooper->status)->toBe(RosterType::Combat)
            ->and($trooper->fetchedAt)->toEqual($fetchedAt);
    });

    it('reads the rank insignia URL Cav serves', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(), $fetchedAt);

        expect($trooper->rankImageUrl)->toBe('https://7cav.us/data/roster_ranks/0/4.jpg');
    });

    it('keeps the numeric cache-busting query the forum puts on its rank images', function () use ($fetchedAt) {
        $rank = ['rankShort' => 'PVT', 'rankFull' => 'Private', 'rankImageUrl' => 'https://7cav.us/data/roster_ranks/0/22.jpg?1741376831', 'rankId' => '22'];

        expect(CavRosterEntry::fromPayload(1, rosterEntryPayload(['rank' => $rank]), $fetchedAt)->rankImageUrl)
            ->toBe('https://7cav.us/data/roster_ranks/0/22.jpg?1741376831');
    });

    it('keeps only an https rank image on 7cav.us with a plain path, since it is written into an [IMG] tag', function (mixed $url) use ($fetchedAt) {
        $rank = ['rankShort' => 'MG', 'rankFull' => 'Major General', 'rankImageUrl' => $url, 'rankId' => '4'];

        expect(CavRosterEntry::fromPayload(1, rosterEntryPayload(['rank' => $rank]), $fetchedAt)->rankImageUrl)->toBeNull();
    })->with([
        'missing' => [null],
        'blank' => [''],
        'not a string' => [12],
        'plain http' => ['http://7cav.us/data/roster_ranks/0/4.jpg'],
        'another host' => ['https://example.com/data/roster_ranks/0/4.jpg'],
        'a host that only starts with 7cav.us' => ['https://7cav.us.example.com/4.jpg'],
        'credentials before the host' => ['https://7cav.us@example.com/4.jpg'],
        'a closing tag in the path' => ['https://7cav.us/4.jpg[/IMG][URL=https://example.com]x[/URL]'],
        'a space' => ['https://7cav.us/4.jpg x'],
        'a query string that is not a plain number' => ['https://7cav.us/4.jpg?x=1'],
        'a query string with a second part' => ['https://7cav.us/4.jpg?1&x=1'],
        'a protocol-relative url' => ['//7cav.us/4.jpg'],
        'a trailing line break' => ["https://7cav.us/data/roster_ranks/0/4.jpg\n"],
    ]);

    it('does not require a resolvable forum user id, unlike CavRosterProfile', function () use ($fetchedAt) {
        // A trooper can be on the roster with no forum account linked yet; this DTO must not
        // punish that with a thrown exception the way the identity-focused DTO does.
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => null]), $fetchedAt);

        expect($trooper->cavId)->toBe(1)
            ->and($trooper->username)->toBeNull()
            ->and($trooper->forumUserId)->toBeNull();
    });

    it('reads the forum identity (user id and username) from the profile', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(), $fetchedAt);

        expect($trooper->forumUserId)->toBe(3)
            ->and($trooper->username)->toBe('Jarvis.A');
    });

    it('keeps the username when the user id is missing, and vice versa', function () use ($fetchedAt) {
        $noId = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => ['username' => 'Jarvis.A']]), $fetchedAt);
        $noName = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => ['userId' => '3', 'username' => '']]), $fetchedAt);

        expect($noId->username)->toBe('Jarvis.A')
            ->and($noId->forumUserId)->toBeNull()
            ->and($noName->username)->toBeNull()
            ->and($noName->forumUserId)->toBe(3);
    });

    it('reads the user id when the API sends it as a bare int rather than a numeric string', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => ['userId' => 3, 'username' => 'Jarvis.A']]), $fetchedAt);

        expect($trooper->forumUserId)->toBe(3);
    });

    it('leaves forumUserId null on a non-numeric or non-positive user id rather than throwing', function () use ($fetchedAt) {
        $garbage = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => ['userId' => 'abc', 'username' => 'Jarvis.A']]), $fetchedAt);
        $zero = CavRosterEntry::fromPayload(1, rosterEntryPayload(['user' => ['userId' => '0', 'username' => 'Jarvis.A']]), $fetchedAt);

        expect($garbage->forumUserId)->toBeNull()
            ->and($garbage->username)->toBe('Jarvis.A')
            ->and($zero->forumUserId)->toBeNull();
    });

    it('never carries the real name', function () use ($fetchedAt) {
        // Data minimisation (2.10): the portal never needs a trooper's real name, so the DTO has
        // nowhere to put it and nothing downstream can store it by accident.
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload(), $fetchedAt);

        expect(get_object_vars($trooper))->not->toHaveKey('realName')
            ->and(serialize($trooper))->not->toContain('Adam Jarvis');
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

    it('reads a positionId or userId only when it is all digits, as MilpacId does, so "1e3" is not position 1000', function (string $id) use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload([
            'user' => ['userId' => $id, 'username' => 'Jarvis.A'],
            'primary' => ['positionTitle' => 'Rifleman', 'positionId' => $id],
        ]), $fetchedAt);

        expect($trooper->positionId)->toBeNull()
            ->and($trooper->forumUserId)->toBeNull();
    })->with(['an exponent' => ['1e3'], 'a decimal' => ['77.3'], 'a leading space' => [' 773'], 'a sign' => ['+773'], 'too long for an int' => ['99999999999999999999']]);

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

    it('trims the text it reads, and reads text that is only spaces as missing', function () use ($fetchedAt) {
        $trooper = CavRosterEntry::fromPayload(1, rosterEntryPayload([
            'primary' => ['positionTitle' => "  Regimental Technical Aide 	", 'positionId' => '773'],
            'user' => ['userId' => '3', 'username' => ' Jarvis.A '],
            'mos' => '   ',
        ]), $fetchedAt);

        expect($trooper->billet)->toBe('Regimental Technical Aide')
            ->and($trooper->username)->toBe('Jarvis.A')
            ->and($trooper->mos)->toBeNull();
    });

    it('rejects a non-positive cav id at construction', function () use ($fetchedAt) {
        new CavRosterEntry(cavId: 0, fetchedAt: $fetchedAt);
    })->throws(CavRequestException::class);
});
