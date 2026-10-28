<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

// Where starting, switching and leaving view as opens (#426). The page the viewer was on is often one the narrower
// preview cannot open, so starting a preview opens the same page for the unit picked, or that page's landing, or Home.
// Every case follows the redirect to the end and expects the page to open.

/**
 * 7CAV → 1-7 → A/1-7 → 1/A/1-7 → {1/1/A/1-7, 2/1/A/1-7}, plus a sibling battalion 2-7. Each section has a trooper,
 * so no landing skips it as empty.
 *
 * @return array<string, Unit>
 */
function voTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $section2 = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    foreach ([$section, $section2] as $placed) {
        Trooper::factory()->create(['status' => TrooperStatus::Combat])->forceFill(['primary_section_id' => $placed->id])->save();
    }

    return compact('regiment', 'battalion', 'company', 'platoon', 'section', 'section2', 'otherBattalion');
}

function voLeaderOf(Unit $unit, int $cavId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $cavId + 10000, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => $cavId]);
}

/** Start viewing as `$kind` `$id` from `$from`, expect `$expected`, and follow it to a page that opens. */
function voEnter(User $viewer, string $from, string $kind, int $id, string $expected): void
{
    test()->actingAs($viewer)->from($from)
        ->post('/view-as', ['kind' => $kind, 'id' => $id])
        ->assertSessionHasNoErrors()
        ->assertRedirect($expected);

    test()->followingRedirects()->get($expected)->assertOk();
}

describe('starting a preview', function () {
    it('opens the roster of the unit picked, from a wider unit\'s roster, keeping the view', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['battalion'], 100);

        voEnter($commander, "/roster/{$tree['battalion']->id}?view=matrix", 'unit', $tree['section']->id, "/roster/{$tree['section']->id}?view=matrix");
    });

    it('opens the rollcall of the section picked, from another section\'s rollcall, keeping the week', function () {
        $tree = voTree();
        $platoonLeader = voLeaderOf($tree['platoon'], 100);
        $week = WeekOf::start(CarbonImmutable::now())->subWeek()->toDateString();

        voEnter($platoonLeader, "/rollcall/{$tree['section']->id}?week={$week}", 'unit', $tree['section2']->id, "/rollcall/{$tree['section2']->id}?week={$week}");
    });

    it('opens the rollcall landing when the unit picked is above a section', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['company'], 100);

        voEnter($commander, "/rollcall/{$tree['section']->id}", 'unit', $tree['platoon']->id, '/rollcall');
    });

    it('opens the report of the unit picked for the same week, from a wider unit\'s report', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['company'], 100);
        $week = WeekOf::start(CarbonImmutable::now())->toDateString();

        voEnter($commander, "/reports/{$tree['company']->id}/{$week}", 'unit', $tree['section']->id, "/reports/{$tree['section']->id}/{$week}");
    });

    it('opens the roll-up of the unit picked, from a wider unit\'s roll-up', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['company'], 100);

        voEnter($commander, "/reports/{$tree['company']->id}", 'unit', $tree['section']->id, "/reports/{$tree['section']->id}");
    });

    it('opens the roster of the unit picked, from a trooper page the preview cannot see', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['company'], 100);
        $trooper = Trooper::query()->where('primary_section_id', $tree['section']->id)->firstOrFail();

        voEnter($commander, "/troopers/{$trooper->id}", 'unit', $tree['section2']->id, "/roster/{$tree['section2']->id}");
    });

    it('opens the readiness matrix of the unit picked', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['company'], 100);

        voEnter($commander, "/readiness/{$tree['company']->id}", 'unit', $tree['platoon']->id, "/readiness/{$tree['platoon']->id}");
    });

    it('opens Home from a page with no unit of its own, such as a settings page', function () {
        $tree = voTree();
        $admin = User::factory()->admin()->create();
        $sectionLeader = voLeaderOf($tree['section'], 100);

        voEnter($admin, '/settings/org-structure', 'user', $sectionLeader->id, '/home');
    });

    it('opens each page\'s landing in a person preview, which opens where that person would', function () {
        $tree = voTree();
        $admin = User::factory()->admin()->create();
        $sectionLeader = voLeaderOf($tree['section'], 100);

        voEnter($admin, "/roster/{$tree['battalion']->id}", 'user', $sectionLeader->id, '/roster');

        $this->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $tree['section']->id));
    });

    it('opens the unit picked when switching from one preview to another', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['battalion'], 100);
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);

        voEnter($commander, "/rollcall/{$tree['section']->id}", 'unit', $tree['section2']->id, "/rollcall/{$tree['section2']->id}");
    });

    it('opens Home for a page on another site, or none at all', function (?string $referer) {
        $tree = voTree();
        $commander = voLeaderOf($tree['battalion'], 100);

        $request = $this->actingAs($commander);
        if ($referer !== null) {
            $request = $request->withHeader('referer', $referer);
        }

        $request->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id])->assertRedirect('/home');
    })->with([
        'another host' => ['https://elsewhere.example/roster/1'],
        'a path read as a host' => ['//elsewhere.example/roster/1'],
        'no referer' => [null],
    ]);
});

describe('leaving a preview', function () {
    it('goes back to the page it was on, which real scope can open', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['battalion'], 100);
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section2']->id]);

        $this->from("/rollcall/{$tree['section2']->id}")->delete('/view-as')->assertRedirect("/rollcall/{$tree['section2']->id}");
        $this->followingRedirects()->get("/rollcall/{$tree['section2']->id}")->assertOk();
    });

    it('opens Home instead of a page on another site', function () {
        $tree = voTree();
        $commander = voLeaderOf($tree['battalion'], 100);
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section2']->id]);

        $this->withHeader('referer', 'https://elsewhere.example/home')->delete('/view-as')->assertRedirect('/home');
    });
});
