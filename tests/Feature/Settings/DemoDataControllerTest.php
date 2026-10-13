<?php

use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #115 — Settings → Demo data. 404 unless DEMO_TOOLS is on; Admin-only; writes refused during a
// view-as preview (1.13's middleware).

beforeEach(function () {
    config()->set('portal.demo_tools', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC'));

    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Squad', '1/1/A/1-7', $platoon->id);
    $units->create(UnitType::Section, '2nd Squad', '2/1/A/1-7', $platoon->id);
    Trooper::factory()->count(5)->create(['primary_section_id' => $this->section->id]);
    // Gone from the roster: counted nowhere, the picker included.
    Trooper::factory()->create(['primary_section_id' => $this->section->id, 'status' => 'inactive']);

    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('shows an Admin the in-scope units with their placed troopers, and the demo rows held', function () {
    $this->actingAs($this->admin)->get('/settings/demo-data')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/DemoData')
            ->where('units', [
                ['id' => $this->battalion->id, 'type' => 'battalion', 'name' => '1st Battalion', 'callsign' => '1-7', 'troopers' => 5],
                ['id' => $this->company->id, 'type' => 'company', 'name' => 'Alpha Company', 'callsign' => 'A/1-7', 'troopers' => 5],
                ['id' => $this->company->id + 1, 'type' => 'platoon', 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'troopers' => 5],
                ['id' => $this->section->id, 'type' => 'section', 'name' => '1st Squad', 'callsign' => '1/1/A/1-7', 'troopers' => 5],
                ['id' => $this->section->id + 1, 'type' => 'section', 'name' => '2nd Squad', 'callsign' => '2/1/A/1-7', 'troopers' => 0],
            ])
            ->where('existing', ['practices' => 0, 'attendance' => 0, 'reports' => 0, 'weeks' => 0])
            ->where('weeks', ['default' => 8, 'max' => 12])
            ->where('recentWeeks', [
                '2026-07-11', '2026-07-18', '2026-07-25', '2026-08-01', '2026-08-08', '2026-08-15',
                '2026-08-22', '2026-08-29', '2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26',
            ])
            ->where('result', null)
            ->where('features.demoTools', true));
});

it('generates for the chosen unit and weeks, then shows the result once', function () {
    $this->actingAs($this->admin)
        ->post('/settings/demo-data', ['unit_id' => $this->company->id, 'weeks' => 2])
        ->assertRedirect('/settings/demo-data');

    expect(SectionPractice::query()->where('is_demo', true)->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('actor_id', $this->admin->id)->count())->toBe(2);

    $this->get('/settings/demo-data')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('result.action', 'generated')
        ->where('result.empty', false)
        ->where('result.generated_weeks', ['2026-09-19', '2026-09-26'])
        ->where('result.summary', 'Generated 2 weeks of demo history for A/1-7: 5 troopers in 1 section, 10 attendance rows, 6 sent reports.')
        ->where('existing.weeks', 2));

    $this->get('/settings/demo-data')->assertInertia(fn (AssertableInertia $page) => $page->where('result', null));
});

it('reports an empty unit as such', function () {
    $this->actingAs($this->admin)->post('/settings/demo-data', ['unit_id' => $this->section->id + 1, 'weeks' => 8]);

    $this->get('/settings/demo-data')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('result.empty', true)
        ->where('result.troopers', 0));
});

it('purges the chosen unit\'s demo history', function () {
    $this->actingAs($this->admin)->post('/settings/demo-data', ['unit_id' => $this->company->id, 'weeks' => 1]);

    $this->delete('/settings/demo-data', ['unit_id' => $this->company->id])->assertRedirect('/settings/demo-data');

    expect(SectionPractice::query()->count() + WeeklyReport::query()->count())->toBe(0);
    $this->get('/settings/demo-data')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('result.action', 'purged')
        ->where('result.reports', 2));
});

it('validates the unit and the week count', function (array $payload, string $field) {
    $this->actingAs($this->admin)->post('/settings/demo-data', $payload)->assertSessionHasErrors($field);

    expect(SectionPractice::query()->count())->toBe(0);
})->with([
    'no unit' => [['weeks' => 8], 'unit_id'],
    'no weeks' => [['unit_id' => 1], 'weeks'],
    'zero weeks' => [['unit_id' => 1, 'weeks' => 0], 'weeks'],
    'too many weeks' => [['unit_id' => 1, 'weeks' => 13], 'weeks'],
]);

it('refuses a unit that does not exist as a field error', function () {
    $this->actingAs($this->admin)->post('/settings/demo-data', ['unit_id' => 99999, 'weeks' => 1])->assertSessionHasErrors('unit_id');
    $this->actingAs($this->admin)->delete('/settings/demo-data', ['unit_id' => 99999])->assertSessionHasErrors('unit_id');
    $this->actingAs($this->admin)->delete('/settings/demo-data')->assertSessionHasErrors('unit_id');
});

it('refuses a non-Admin, even a leader with scope over the unit', function () {
    $leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->company->id]);

    $this->actingAs($leader)->get('/settings/demo-data')->assertForbidden();
    $this->actingAs($leader)->post('/settings/demo-data', ['unit_id' => $this->company->id, 'weeks' => 1])->assertForbidden();
    $this->actingAs($leader)->delete('/settings/demo-data', ['unit_id' => $this->company->id])->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0);
});

it('404s every route while demo tools are off, and the nav flag is off', function () {
    config()->set('portal.demo_tools', false);

    $this->actingAs($this->admin)->get('/settings/demo-data')->assertNotFound();
    $this->actingAs($this->admin)->post('/settings/demo-data', ['unit_id' => $this->company->id, 'weeks' => 1])->assertNotFound();
    $this->actingAs($this->admin)->delete('/settings/demo-data', ['unit_id' => $this->company->id])->assertNotFound();
    $this->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('features.demoTools', false));

    expect(SectionPractice::query()->count())->toBe(0);
});

it('refuses writes while the Admin is viewing as a unit', function () {
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->company->id])->assertSessionHasNoErrors();

    $this->post('/settings/demo-data', ['unit_id' => $this->company->id, 'weeks' => 1])->assertForbidden();
    $this->delete('/settings/demo-data', ['unit_id' => $this->company->id])->assertForbidden();
    // A unit's leader is not an Admin, so the preview does not see the screen either.
    $this->get('/settings/demo-data')->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0);
});

it('requires authentication', function () {
    $this->get('/settings/demo-data')->assertRedirect(route('login'));
    $this->post('/settings/demo-data', ['unit_id' => 1, 'weeks' => 1])->assertRedirect(route('login'));
});
