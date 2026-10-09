<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Story 6.8: every way into a trooper's data goes through the one ScopeResolver gate, so the milpac, the readiness
// matrix and the quick-action links can never disagree about who may look.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A (section, led by the viewer)
//                                    │       └─ 2/1/A
//                                    └─ 2/A ─ 1/2/A

function gateTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

beforeEach(function () {
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $this->platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon1->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon1->id);
    $this->section3 = $units->create(UnitType::Section, '1st Section, 2nd Platoon', '1/2/A/1-7', $this->platoon2->id);

    UnitCommandMapping::factory()->create(['unit_id' => $this->section1->id, 'cav_position_id' => 501]);
    $leaderTrooper = gateTrooper($this->section1, ['cav_position_id' => 501]);

    $staff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->platoon1->id]);

    // Every kind of viewer the gate has to treat consistently.
    $this->viewers = [
        'section leader' => User::factory()->create(['cav_id' => $leaderTrooper->cav_id]),
        'platoon staff' => $staff,
        'no scope' => User::factory()->create(),
        'admin' => User::factory()->admin()->create(),
    ];
    $this->troopers = [
        'own section' => gateTrooper($this->section1, ['username' => 'Own.Section', 'billet' => 'Rifleman Alpha']),
        'sibling section' => gateTrooper($this->section2, ['username' => 'Sibling.Section', 'billet' => 'Rifleman Bravo']),
        'other platoon' => gateTrooper($this->section3, ['username' => 'Other.Platoon', 'billet' => 'Rifleman Charlie']),
    ];
});

it('withholds an out-of-scope trooper\'s data from the response, not just the page', function () {
    $secret = $this->troopers['other platoon'];
    $course = Course::factory()->create(['name' => 'Hidden Qualification Course']);
    TrooperCourse::factory()->create(['trooper_id' => $secret->id, 'course_id' => $course->id, 'status' => CourseStatus::Qualified]);

    foreach ([[], ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest']] as $headers) {
        $body = $this->actingAs($this->viewers['section leader'])->withHeaders($headers)->get(route('troopers.show', $secret))->assertForbidden()->getContent();

        foreach (['Other.Platoon', 'Rifleman Charlie', 'Hidden Qualification Course'] as $leaked) {
            expect($body)->not->toContain($leaked);
        }
    }
});

it('withholds an out-of-scope unit\'s troopers from the readiness matrix response', function () {
    $body = $this->actingAs($this->viewers['section leader'])->get(route('readiness.matrix', $this->section3->id))->assertForbidden()->getContent();

    expect($body)->not->toContain('Other.Platoon')->not->toContain('Rifleman Charlie');
});

it('sends a signed-out visitor to sign in from every Epic 6 route', function () {
    $this->get(route('troopers.show', $this->troopers['own section']))->assertRedirect(route('login'));
    $this->get(route('readiness.matrix', $this->section1->id))->assertRedirect(route('login'));
});

it('lets the milpac and the matrix agree on who may look, for every viewer and every trooper', function () {
    $expected = [
        'section leader' => ['own section' => 200, 'sibling section' => 403, 'other platoon' => 403],
        'platoon staff' => ['own section' => 200, 'sibling section' => 200, 'other platoon' => 403],
        'no scope' => ['own section' => 403, 'sibling section' => 403, 'other platoon' => 403],
        'admin' => ['own section' => 200, 'sibling section' => 200, 'other platoon' => 200],
    ];

    foreach ($this->viewers as $viewerName => $viewer) {
        foreach ($this->troopers as $trooperName => $trooper) {
            $milpac = $this->actingAs($viewer)->get(route('troopers.show', $trooper))->getStatusCode();
            $matrix = $this->actingAs($viewer)->get(route('readiness.matrix', $trooper->primary_section_id))->getStatusCode();

            expect($milpac)->toBe($expected[$viewerName][$trooperName], "{$viewerName} opening {$trooperName} on the milpac")
                ->and($matrix)->toBe($milpac, "{$viewerName} opening {$trooperName}'s section on the matrix");
        }
    }
});

it('never offers a link the viewer would be refused', function () {
    foreach ($this->viewers as $viewerName => $viewer) {
        foreach ($this->troopers as $trooperName => $trooper) {
            $response = $this->actingAs($viewer)->get(route('troopers.show', $trooper));

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            foreach ($response->viewData('page')['props']['actions'] as $action) {
                expect($this->actingAs($viewer)->get($action['href'])->getStatusCode())->toBe(200, "{$viewerName} on {$trooperName}: {$action['key']} -> {$action['href']}");
            }
        }
    }
});

it('refuses a view-as preview what the previewed leader could not see, on both pages', function () {
    $this->actingAs($this->viewers['admin'])->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

    $this->get(route('troopers.show', $this->troopers['own section']))->assertOk();
    $this->get(route('troopers.show', $this->troopers['sibling section']))->assertForbidden();
    $this->get(route('readiness.matrix', $this->section1->id))->assertOk();
    $this->get(route('readiness.matrix', $this->section2->id))->assertForbidden();
});

it('shows a trooper no section holds to an Admin only, on the milpac', function () {
    $unplaced = Trooper::factory()->create(['username' => 'Not.Placed']);

    $this->actingAs($this->viewers['platoon staff'])->get(route('troopers.show', $unplaced))->assertForbidden();
    $this->actingAs($this->viewers['admin'])->get(route('troopers.show', $unplaced))->assertOk();
});

it('lets a dual-hatted viewer open a trooper in either subtree they hold', function () {
    $dual = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $dual->id, 'unit_id' => $this->section1->id]);
    PortalRoleGrant::factory()->create(['user_id' => $dual->id, 'unit_id' => $this->section3->id]);

    $this->actingAs($dual)->get(route('troopers.show', $this->troopers['own section']))->assertOk();
    $this->get(route('troopers.show', $this->troopers['other platoon']))->assertOk();
    $this->get(route('troopers.show', $this->troopers['sibling section']))->assertForbidden();
});
