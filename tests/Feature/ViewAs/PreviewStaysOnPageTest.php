<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// Starting or stopping view as or act as keeps the page it was started from. A page the previewed or acted-as identity
// may not open keeps its 403 or 404, but renders the shell's blocked page with no data of the page, so the banner and
// its way out stay. The sidebar's Templates link follows the template writes' own rule.

/** @return array<string, Unit> 7CAV → 1-7 → A/1-7 → 1/A/1-7 → 1/1/A/1-7, with a trooper in the section. */
function psTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    Trooper::factory()->create(['status' => TrooperStatus::Combat])->forceFill(['primary_section_id' => $section->id])->save();

    return compact('regiment', 'battalion', 'company', 'platoon', 'section');
}

function psLeaderOf(Unit $unit, int $cavId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $cavId + 10000, 'status' => TrooperStatus::Combat])
        ->forceFill(['primary_section_id' => $unit->type === UnitType::Section ? $unit->id : null])->save();

    return User::factory()->create(['cav_id' => $cavId]);
}

function psAdmin(): User
{
    Trooper::factory()->create(['cav_id' => 950, 'status' => TrooperStatus::Combat]);

    return User::factory()->admin()->create(['cav_id' => 950]);
}

describe('act as keeps the page', function () {
    it('stays on the page it was started from', function () {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);

        $this->actingAs($admin)->from("/rollcall/{$tree['section']->id}")
            ->post('/act-as', ['kind' => 'user', 'id' => $leader->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/rollcall/{$tree['section']->id}");
    });

    it('stays on a billet\'s page too', function () {
        $tree = psTree();
        $admin = psAdmin();

        $this->actingAs($admin)->from('/roster?view=orbat')
            ->post('/act-as', ['kind' => 'unit', 'id' => $tree['section']->id])
            ->assertRedirect('/roster?view=orbat');
    });

    it('returns to the same page when it stops', function () {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);
        $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $leader->id]);

        $this->actingAs($admin)->from('/settings/audit-log')->delete('/act-as')->assertRedirect('/settings/audit-log');
        $this->actingAs($admin)->get('/settings/audit-log')->assertOk();
    });

    it('opens Home for a page on another site', function () {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);

        $this->actingAs($admin)->withHeader('referer', 'https://elsewhere.example/roster')
            ->post('/act-as', ['kind' => 'user', 'id' => $leader->id])
            ->assertRedirect('/home');
    });

    it('never sends the user off this site, by any Referer that could read as another host', function (string $referer) {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);

        $this->actingAs($admin)->withHeader('referer', $referer)
            ->post('/act-as', ['kind' => 'user', 'id' => $leader->id])
            ->assertRedirect('/home');
        $this->actingAs($admin)->withHeader('referer', $referer)->delete('/act-as')->assertRedirect('/home');
        $this->actingAs($admin)->withHeader('referer', $referer)
            ->post('/view-as', ['kind' => 'user', 'id' => $leader->id])
            ->assertRedirect('/home');
        $this->actingAs($admin)->withHeader('referer', $referer)->delete('/view-as')->assertRedirect('/home');
    })->with([
        'protocol-relative' => '//elsewhere.example/roster',
        'double slash path' => 'http://localhost//elsewhere.example/roster',
        'backslash path' => 'http://localhost/\elsewhere.example/roster',
        'other scheme' => 'javascript:alert(1)',
    ]);
});

describe('view as keeps the page', function () {
    it('stays on a page that has no version for the target', function () {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);

        $this->actingAs($admin)->from('/settings/audit-log?page=2')
            ->post('/view-as', ['kind' => 'user', 'id' => $leader->id])
            ->assertRedirect('/settings/audit-log?page=2');
    });

    it('returns to the same page when it ends', function () {
        $tree = psTree();
        $admin = psAdmin();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);

        $this->actingAs($admin)->from('/settings/audit-log')->delete('/view-as')->assertRedirect('/settings/audit-log');
    });
});

describe('a page the identity cannot open', function () {
    it('renders the blocked page while viewing as a person, with the status and none of the page', function () {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);
        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id]);

        $response = $this->actingAs($admin)->get('/settings/audit-log')->assertForbidden();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Blocked')
            ->where('mode', 'viewAs')
            ->where('kind', 'trooper')
            ->missing('entries')
            ->missing('filters')
            ->where('viewAs.kind', 'user'));
    });

    it('names the unit while viewing as a unit', function () {
        $tree = psTree();
        $admin = psAdmin();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);

        $this->actingAs($admin)->get('/settings/audit-log')->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Blocked')->where('mode', 'viewAs')->where('kind', 'unit'));
    });

    it('renders it while acting as a person, and as a billet, and keeps a 404 a 404', function () {
        $tree = psTree();
        $admin = psAdmin();
        $leader = psLeaderOf($tree['section'], 100);
        $outside = Trooper::factory()->create(['status' => TrooperStatus::Combat]);

        $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $leader->id]);
        $this->actingAs($admin)->get('/settings/audit-log')->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Blocked')->where('mode', 'actAs')->where('kind', 'trooper')
                ->where('actingAs.kind', 'user'));
        $this->actingAs($admin)->get("/troopers/{$outside->id}")->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Blocked')->missing('trooper'));
        $this->actingAs($admin)->delete('/act-as');

        $this->actingAs($admin)->post('/act-as', ['kind' => 'unit', 'id' => $tree['section']->id]);
        $this->actingAs($admin)->get('/settings/audit-log')->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Blocked')->where('mode', 'actAs')->where('kind', 'billet'));
    });

    it('keeps the plain refusal without a preview, and for a write during one', function () {
        $tree = psTree();
        $leader = psLeaderOf($tree['section'], 100);

        $response = $this->actingAs($leader)->get('/settings/audit-log')->assertForbidden();
        expect($response->headers->get('X-Inertia'))->toBeNull()
            ->and($response->getContent())->not->toContain('"component":"Blocked"');

        $admin = psAdmin();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);
        $write = $this->actingAs($admin)->post('/settings/templates', [])->assertForbidden();
        expect($write->getContent())->not->toContain('"component":"Blocked"');
    });
});

describe('the Templates link', function () {
    it('is offered to whoever may manage some unit\'s templates', function (string $who, bool $offered) {
        $tree = psTree();
        $user = match ($who) {
            'section leader' => psLeaderOf($tree['section'], 101),
            'platoon leader' => psLeaderOf($tree['platoon'], 102),
            'company commander' => psLeaderOf($tree['company'], 103),
            'plain trooper' => User::factory()->create(['cav_id' => Trooper::factory()->create(['status' => TrooperStatus::Combat])->cav_id]),
            'admin' => psAdmin(),
        };

        $this->actingAs($user)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canManageTemplates', $offered));
    })->with([
        'SL' => ['section leader', true],
        'PL' => ['platoon leader', true],
        'CO' => ['company commander', true],
        'plain trooper' => ['plain trooper', false],
        'Admin' => ['admin', true],
    ]);

    it('is offered to an Admin acting as a company commander, and not during a preview', function () {
        $tree = psTree();
        $admin = psAdmin();
        $commander = psLeaderOf($tree['company'], 103);

        $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $commander->id]);
        $this->actingAs($admin)->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_admin', false)
            ->where('canManageTemplates', true));
        $this->actingAs($admin)->delete('/act-as');

        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $commander->id]);
        $this->actingAs($admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canManageTemplates', false));
    });
});
