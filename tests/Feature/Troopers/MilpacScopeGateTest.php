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

    $staff = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->platoon1->id]);

    // Every kind of viewer the gate has to treat consistently.
    $this->viewers = [
        'section leader' => User::factory()->create(['cav_id' => $leaderTrooper->cav_id]),
        'platoon staff' => $staff,
        'no scope' => User::factory()->serving()->create(),
        'admin' => User::factory()->admin()->create(),
    ];
    $this->troopers = [
        'own section' => gateTrooper($this->section1, ['username' => 'Own.Section', 'billet' => 'Rifleman Alpha']),
        'sibling section' => gateTrooper($this->section2, ['username' => 'Sibling.Section', 'billet' => 'Rifleman Bravo']),
        'other platoon' => gateTrooper($this->section3, ['username' => 'Other.Platoon', 'billet' => 'Rifleman Charlie']),
    ];
});

// What a page request and an Inertia (XHR) request each send, so a refusal is checked in both shapes.
function gateRequestShapes(): array
{
    return [
        'page' => [],
        'inertia' => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'],
    ];
}

it('withholds an out-of-scope trooper\'s data from the response, not just the page', function () {
    $secret = $this->troopers['other platoon'];
    $course = Course::factory()->create(['name' => 'Hidden Qualification Course']);
    TrooperCourse::factory()->create(['trooper_id' => $secret->id, 'course_id' => $course->id, 'status' => CourseStatus::Qualified]);
    $private = ['Other.Platoon', 'Rifleman Charlie', 'Hidden Qualification Course'];

    // The control: a viewer who may look does receive these strings, so the refusals below could have leaked them.
    $allowed = $this->actingAs($this->viewers['admin'])->withHeaders(gateRequestShapes()['inertia'])->get(route('troopers.show', $secret))->assertOk()->getContent();

    foreach ($private as $value) {
        expect($allowed)->toContain($value);
    }

    foreach (gateRequestShapes() as $shape => $headers) {
        $body = $this->actingAs($this->viewers['section leader'])->withHeaders($headers)->get(route('troopers.show', $secret))->assertNotFound()->getContent();

        foreach ($private as $value) {
            expect($body)->not->toContain($value, "the {$shape} refusal leaked {$value}");
        }
    }
});

it('withholds an out-of-scope unit\'s troopers from the readiness matrix response', function () {
    $private = ['Other.Platoon', 'Rifleman Charlie'];
    $allowed = $this->actingAs($this->viewers['admin'])->withHeaders(gateRequestShapes()['inertia'])->get(route('readiness.matrix', $this->section3->id))->assertOk()->getContent();

    foreach ($private as $value) {
        expect($allowed)->toContain($value);
    }

    foreach (gateRequestShapes() as $shape => $headers) {
        $body = $this->actingAs($this->viewers['section leader'])->withHeaders($headers)->get(route('readiness.matrix', $this->section3->id))->assertForbidden()->getContent();

        foreach ($private as $value) {
            expect($body)->not->toContain($value, "the {$shape} refusal leaked {$value}");
        }
    }
});

it('sends a signed-out visitor to sign in from every Epic 6 route', function () {
    $this->get(route('troopers.show', $this->troopers['own section']))->assertRedirect(route('login'));
    $this->get(route('readiness.matrix', $this->section1->id))->assertRedirect(route('login'));
});

// Who may open what, and why. The milpac answers 404 for a trooper out of scope (so ids cannot be enumerated); the
// matrix answers 403 for a unit out of scope. Both refuse together, whatever the status code.
function gateExpected(): array
{
    return [
        // A section leader holds only their own section.
        'section leader' => ['own section' => true, 'sibling section' => false, 'other platoon' => false],
        // Platoon staff hold the whole platoon (both its sections), not the other platoon.
        'platoon staff' => ['own section' => true, 'sibling section' => true, 'other platoon' => false],
        // Someone with no grant and no command billet holds nothing.
        'no scope' => ['own section' => false, 'sibling section' => false, 'other platoon' => false],
        // An Admin holds everything.
        'admin' => ['own section' => true, 'sibling section' => true, 'other platoon' => true],
    ];
}

dataset('gate pairs', function () {
    foreach (gateExpected() as $viewer => $troopers) {
        foreach ($troopers as $trooper => $allowed) {
            yield "{$viewer} opening {$trooper}" => [$viewer, $trooper, $allowed];
        }
    }
});

it('lets the milpac and the matrix agree on who may look', function (string $viewer, string $trooper, bool $allowed) {
    $person = $this->troopers[$trooper];

    $milpac = $this->actingAs($this->viewers[$viewer])->get(route('troopers.show', $person))->getStatusCode();
    $matrix = $this->actingAs($this->viewers[$viewer])->get(route('readiness.matrix', $person->primary_section_id))->getStatusCode();

    expect($milpac)->toBe($allowed ? 200 : 404, 'the milpac')
        ->and($matrix)->toBe($allowed ? 200 : 403, "the matrix of {$trooper}'s section");
})->with('gate pairs');

dataset('viewers with actions', [
    'section leader' => ['section leader', ['section-roster', 'rollcall']],
    'platoon staff' => ['platoon staff', ['section-roster', 'rollcall', 'readiness-matrix']],
    'admin' => ['admin', ['section-roster', 'rollcall', 'readiness-matrix']],
]);

it('never offers a link the viewer would be refused, and does offer the expected ones', function (string $viewer, array $expectedKeys) {
    $followed = [];

    foreach ($this->troopers as $trooper) {
        $response = $this->actingAs($this->viewers[$viewer])->get(route('troopers.show', $trooper));

        if ($response->getStatusCode() !== 200) {
            continue;
        }

        foreach ($response->viewData('page')['props']['actions'] as $action) {
            expect($this->actingAs($this->viewers[$viewer])->get($action['href'])->getStatusCode())->toBe(200, "{$action['key']} -> {$action['href']}");
            $followed[$action['key']] = true;
        }
    }

    // A loop over nothing proves nothing: every kind of link this viewer is meant to get was actually followed.
    expect(array_keys($followed))->toEqualCanonicalizing($expectedKeys);
})->with('viewers with actions');

it('refuses a view-as preview what the previewed leader could not see, on both pages', function () {
    $this->actingAs($this->viewers['admin'])->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

    $this->get(route('troopers.show', $this->troopers['own section']))->assertOk();
    $this->get(route('troopers.show', $this->troopers['sibling section']))->assertNotFound();
    $this->get(route('readiness.matrix', $this->section1->id))->assertOk();
    $this->get(route('readiness.matrix', $this->section2->id))->assertForbidden();
});

it('shows a trooper no section holds to an Admin only, on the milpac', function () {
    $unplaced = Trooper::factory()->create(['username' => 'Not.Placed']);

    $this->actingAs($this->viewers['platoon staff'])->get(route('troopers.show', $unplaced))->assertNotFound();
    $this->actingAs($this->viewers['admin'])->get(route('troopers.show', $unplaced))->assertOk();
});

it('lets a dual-hatted viewer open a trooper in either subtree they hold', function () {
    $dual = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $dual->id, 'unit_id' => $this->section1->id]);
    PortalRoleGrant::factory()->create(['user_id' => $dual->id, 'unit_id' => $this->section3->id]);

    $this->actingAs($dual)->get(route('troopers.show', $this->troopers['own section']))->assertOk();
    $this->get(route('troopers.show', $this->troopers['other platoon']))->assertOk();
    $this->get(route('troopers.show', $this->troopers['sibling section']))->assertNotFound();
});
