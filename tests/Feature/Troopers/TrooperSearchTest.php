<?php

use App\Http\Controllers\TrooperSearchController;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// The shell's trooper search: it may offer only the troopers whose milpac the viewer could open, so it never tells
// anyone who exists outside their scope.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A (section, led by the viewer)
//                                    │       └─ 2/1/A
//                                    └─ 2/A ─ 1/2/A

function searchTrooper(?Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section?->id])->save();

    return $trooper;
}

function searchFor($test, User $viewer, string $q)
{
    return $test->actingAs($viewer)->getJson(route('troopers.search', ['q' => $q]));
}

beforeEach(function () {
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon1->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon1->id);
    $this->section3 = $units->create(UnitType::Section, '1st Section, 2nd Platoon', '1/2/A/1-7', $platoon2->id);

    UnitCommandMapping::factory()->create(['unit_id' => $this->section1->id, 'cav_position_id' => 501]);
    $leaderTrooper = searchTrooper($this->section1, ['cav_position_id' => 501, 'username' => 'Leader.L']);

    $staff = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $platoon1->id]);

    $this->viewers = [
        'section leader' => User::factory()->create(['cav_id' => $leaderTrooper->cav_id]),
        'platoon staff' => $staff,
        'no scope' => User::factory()->serving()->create(),
        'admin' => User::factory()->admin()->create(),
    ];
    // Every username shares `Xqz`, so one search reaches all of them and only the scope decides who comes back.
    $this->troopers = [
        'own section' => searchTrooper($this->section1, ['username' => 'Xqz.Own']),
        'own section, departed' => searchTrooper($this->section1, ['username' => 'Xqz.Gone', 'status' => TrooperStatus::Inactive]),
        'sibling section' => searchTrooper($this->section2, ['username' => 'Xqz.Sibling']),
        'other platoon' => searchTrooper($this->section3, ['username' => 'Xqz.Other']),
        'unplaced' => searchTrooper(null, ['username' => 'Xqz.Unplaced']),
    ];
});

describe('who it offers', function () {
    dataset('search viewers', ['section leader', 'platoon staff', 'no scope', 'admin']);

    it('offers exactly the troopers whose milpac the viewer can open', function (string $viewer) {
        $found = collect(searchFor($this, $this->viewers[$viewer], 'xqz')->assertOk()->json('troopers'))->pluck('id');

        foreach ($this->troopers as $label => $trooper) {
            $page = $this->actingAs($this->viewers[$viewer])->get(route('troopers.show', $trooper))->getStatusCode();

            expect($page)->toBeIn([200, 404])
                ->and($found->contains($trooper->id))->toBe($page === 200, "{$viewer} searching for {$label} (milpac answered {$page})");
        }
    })->with('search viewers');

    it('gives a section leader their own section only, and an Admin everyone, unplaced and departed included', function () {
        $names = fn (string $viewer) => collect(searchFor($this, $this->viewers[$viewer], 'xqz')->json('troopers'))->pluck('name')->all();

        expect($names('section leader'))->toEqualCanonicalizing(['Xqz.Own', 'Xqz.Gone'])
            ->and($names('platoon staff'))->toEqualCanonicalizing(['Xqz.Own', 'Xqz.Gone', 'Xqz.Sibling'])
            ->and($names('no scope'))->toBe([])
            ->and($names('admin'))->toEqualCanonicalizing(['Xqz.Own', 'Xqz.Gone', 'Xqz.Sibling', 'Xqz.Other', 'Xqz.Unplaced']);
    });

    it('leaves out-of-scope troopers out of the response body entirely', function () {
        $body = searchFor($this, $this->viewers['section leader'], 'xqz.other')->assertOk()->getContent();

        expect(json_decode($body, true))->toBe(['troopers' => [], 'more' => false, 'scoped' => true])
            ->and($body)->not->toContain('Xqz.Other')
            ->and($body)->not->toContain('1/2/A/1-7');
    });

    it('narrows to a view-as preview, as the milpac does', function () {
        $this->actingAs($this->viewers['admin'])->post('/view-as', ['kind' => 'unit', 'id' => $this->section2->id])->assertSessionHasNoErrors();

        $names = collect($this->getJson(route('troopers.search', ['q' => 'xqz']))->assertOk()->json('troopers'))->pluck('name')->all();

        // A preview of a section is not an Admin, so the unplaced trooper drops out too.
        expect($names)->toBe(['Xqz.Sibling']);
    });

    it('answers a signed-out visitor 401 without results, as every fetch is answered', function () {
        $response = $this->getJson(route('troopers.search', ['q' => 'xqz']))->assertUnauthorized();

        expect($response->getContent())->not->toContain('Xqz');
    });
});

describe('how it matches', function () {
    it('matches part of a username, ignoring case', function (string $q) {
        $names = collect(searchFor($this, $this->viewers['admin'], $q)->assertOk()->json('troopers'))->pluck('name')->all();

        expect($names)->toBe(['Xqz.Own']);
    })->with(['xqz.own', 'XQZ.OWN', 'z.Ow', '.own']);

    it('treats % and _ as plain characters, not wildcards', function () {
        searchTrooper($this->section1, ['username' => 'Under_Score']);

        $names = fn (string $q) => collect(searchFor($this, $this->viewers['admin'], $q)->json('troopers'))->pluck('name')->all();

        expect($names('%%'))->toBe([])
            ->and($names('r_s'))->toBe(['Under_Score'])
            // `_` as a wildcard would also match the `.` in every `Xqz.` name.
            ->and($names('z_'))->toBe([]);
    });

    it('lists serving troopers first, a username starting with the text before one containing it, then by name', function () {
        searchTrooper($this->section1, ['username' => 'Alpha.Xqz']);
        searchTrooper($this->section1, ['username' => 'Aaa.Xqz', 'status' => TrooperStatus::Inactive]);

        $names = collect(searchFor($this, $this->viewers['admin'], 'xqz')->json('troopers'))->pluck('name')->all();

        expect($names)->toBe(['Xqz.Other', 'Xqz.Own', 'Xqz.Sibling', 'Xqz.Unplaced', 'Alpha.Xqz', 'Xqz.Gone', 'Aaa.Xqz']);
    });

    it('shows each trooper with rank, name, section and whether they have left', function () {
        $this->troopers['own section']->forceFill(['rank' => 'Sergeant', 'rank_short' => 'SGT'])->save();

        $rows = collect(searchFor($this, $this->viewers['admin'], 'xqz')->json('troopers'))->keyBy('name');

        expect($rows['Xqz.Own'])->toBe([
            'id' => $this->troopers['own section']->id,
            'rank' => 'SGT',
            'rankFull' => 'Sergeant',
            'name' => 'Xqz.Own',
            'displayName' => 'SGT Xqz.Own',
            'section' => ['id' => $this->section1->id, 'designation' => '1/1/A/1-7', 'name' => '1st Section'],
            'departed' => false,
        ])
            ->and($rows['Xqz.Gone']['departed'])->toBeTrue()
            ->and($rows['Xqz.Unplaced']['section'])->toBeNull();
    });
});

describe('how 7Cav writes a name', function () {
    beforeEach(function () {
        $this->vasquez = searchTrooper($this->section1, ['username' => 'Vasquez.R', 'rank_short' => 'SGT']);
        $this->spaced = searchTrooper($this->section1, ['username' => 'Kim Lee', 'rank_short' => 'PFC']);
        $this->nameless = searchTrooper($this->section1, ['username' => null, 'rank_short' => 'PVT', 'cav_id' => 48213]);
    });

    $names = fn ($test, string $q, string $viewer = 'admin') => collect(searchFor($test, $test->viewers[$viewer], $q)->assertOk()->json('troopers'))->pluck('name')->all();

    it('ignores a leading rank and reads a space as the username\'s dot', function (string $q) use ($names) {
        expect($names($this, $q))->toBe(['Vasquez.R']);
    })->with(['SGT Vasquez', 'sgt vasquez', 'Vasquez R', 'SGT Vasquez R', 'Sgt. Vasquez.R', 'cpl vasq', '  SGT   Vasquez  ', 'GOA Vasquez']);

    it('knows the same ranks as the roster, so a search may start with any of them', function () {
        preg_match('/export const RANK_ORDER = \[(.*?)\];/s', file_get_contents(resource_path('js/lib/roster.js')), $match);
        preg_match_all("/'([A-Z0-9]+)'/", $match[1], $roster);

        expect((new ReflectionClassConstant(TrooperSearchController::class, 'RANKS'))->getValue())->toBe($roster[1]);
    });

    it('keeps a rank that is the whole search as text to match', function () use ($names) {
        searchTrooper($this->section1, ['username' => 'Sgtson.A']);

        expect($names($this, 'SGT'))->toBe(['Sgtson.A']);
    });

    it('still finds a username that has a space of its own', function () use ($names) {
        expect($names($this, 'kim lee'))->toBe(['Kim Lee'])
            ->and($names($this, 'PFC Kim Lee'))->toBe(['Kim Lee']);
    });

    it('finds a trooper by their exact cav id, which is how one with no username is named', function (string $q) {
        $rows = searchFor($this, $this->viewers['admin'], $q)->assertOk()->json('troopers');

        expect(collect($rows)->pluck('id')->all())->toBe([$this->nameless->id])
            ->and($rows[0]['displayName'])->toBe('PVT #48213');
    })->with(['48213', '#48213']);

    it('matches a cav id only exactly, never as part of one', function () use ($names) {
        expect($names($this, '4821'))->toBe([]);
    });

    it('keeps the scope rule for a cav id search', function () {
        $outside = searchTrooper($this->section3, ['username' => null, 'cav_id' => 59001]);

        expect(searchFor($this, $this->viewers['section leader'], '59001')->assertOk()->json('troopers'))->toBe([])
            ->and(collect(searchFor($this, $this->viewers['admin'], '59001')->json('troopers'))->pluck('id')->all())->toBe([$outside->id]);
    });

    it('says whether the list is limited to the viewer\'s units', function () {
        expect(searchFor($this, $this->viewers['section leader'], 'vasquez')->json('scoped'))->toBeTrue()
            ->and(searchFor($this, $this->viewers['admin'], 'vasquez')->json('scoped'))->toBeFalse();
    });
});

describe('limits', function () {
    it('returns at most the limit, and says when there are more', function () {
        foreach (range(1, TrooperSearchController::LIMIT + 2) as $n) {
            searchTrooper($this->section1, ['username' => sprintf('Qmany.%02d', $n)]);
        }

        $response = searchFor($this, $this->viewers['admin'], 'qmany')->assertOk();

        expect($response->json('troopers'))->toHaveCount(TrooperSearchController::LIMIT)
            ->and($response->json('more'))->toBeTrue()
            ->and(collect($response->json('troopers'))->pluck('name')->first())->toBe('Qmany.01');
    });

    it('says there are no more when the limit is met exactly', function () {
        foreach (range(1, TrooperSearchController::LIMIT) as $n) {
            searchTrooper($this->section1, ['username' => sprintf('Qmany.%02d', $n)]);
        }

        $response = searchFor($this, $this->viewers['admin'], 'qmany')->assertOk();

        expect($response->json('troopers'))->toHaveCount(TrooperSearchController::LIMIT)
            ->and($response->json('more'))->toBeFalse();
    });

    it('refuses fewer than 2 characters, after trimming, and more than 64', function (?string $q) {
        $this->actingAs($this->viewers['admin'])
            ->getJson(route('troopers.search', $q === null ? [] : ['q' => $q]))
            ->assertUnprocessable()
            ->assertJsonMissingPath('troopers');
    })->with(['missing' => null, 'empty' => '', 'one character' => 'x', 'one with spaces' => '  x  ', 'too long' => str_repeat('x', 65)]);

    it('refuses a q that is not a string', function () {
        $this->actingAs($this->viewers['admin'])->getJson('/troopers/search?q[]=xqz')->assertUnprocessable();
    });

    it('throttles a user who searches too fast, without slowing anyone else', function () {
        foreach (range(1, 60) as $ignored) {
            searchFor($this, $this->viewers['admin'], 'xqz')->assertOk();
        }

        searchFor($this, $this->viewers['admin'], 'xqz')->assertTooManyRequests();
        searchFor($this, $this->viewers['platoon staff'], 'xqz')->assertOk();
    });
});
