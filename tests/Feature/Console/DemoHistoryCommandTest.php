<?php

use App\Models\AuditLogEntry;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

// #115 — `php artisan portal:demo-history {unit} {--weeks=8} {--purge} {--as=}`.

beforeEach(function () {
    config()->set('portal.demo_tools', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC'));

    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Squad', '1/1/A/1-7', $platoon->id);
    $this->empty = $units->create(UnitType::Section, '2nd Squad', '2/1/A/1-7', $platoon->id);
    Trooper::factory()->count(4)->create(['primary_section_id' => $this->section->id]);

    $this->admin = User::factory()->admin()->create(['username' => 'Gryspeert.J']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('generates the requested weeks for a designation, recording the earliest Admin', function () {
    User::factory()->admin()->create(['username' => 'Later.A']);

    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--weeks' => 2])
        ->expectsOutput('Generated 2 weeks of demo history for A/1-7: 4 troopers in 1 section, 8 attendance rows, 6 sent reports.')
        ->assertSuccessful();

    expect(SectionPractice::query()->count())->toBe(2)
        ->and(AuditLogEntry::query()->pluck('actor_id')->unique()->all())->toBe([$this->admin->id]);
});

it('defaults to eight weeks and accepts a unit id', function () {
    $this->artisan('portal:demo-history', ['unit' => (string) $this->section->id])->assertSuccessful();

    expect(SectionPractice::query()->count())->toBe(8);
});

it('records the --as Admin', function () {
    $other = User::factory()->admin()->create(['username' => 'Other.A']);

    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--weeks' => 1, '--as' => 'Other.A'])->assertSuccessful();

    expect(WeeklyReport::query()->pluck('sent_by')->unique()->all())->toBe([$other->id]);
});

it('purges with --purge', function () {
    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--weeks' => 1])->assertSuccessful();

    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--purge' => true])
        ->expectsOutput('Purged demo history for A/1-7: 1 practices, 4 attendance rows and 2 reports across 1 week.')
        ->assertSuccessful();

    expect(SectionPractice::query()->count() + WeeklyReport::query()->count())->toBe(0);
});

it('says so when the unit has no placed troopers', function () {
    $this->artisan('portal:demo-history', ['unit' => '2/1/A/1-7'])
        ->expectsOutput("No troopers are placed in 2/1/A/1-7's sections, so no demo history was generated.")
        ->assertSuccessful();
});

it('refuses while demo tools are disabled', function () {
    config()->set('portal.demo_tools', false);

    $this->artisan('portal:demo-history', ['unit' => 'A/1-7'])
        ->expectsOutputToContain('Demo tools are disabled')
        ->assertFailed();

    expect(SectionPractice::query()->count())->toBe(0);
});

it('refuses an unknown unit, an out-of-range week count, and a missing Admin', function () {
    $this->artisan('portal:demo-history', ['unit' => 'Z/9-9'])->expectsOutputToContain("No unit is designated 'Z/9-9'")->assertFailed();
    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--weeks' => 13])->expectsOutputToContain('--weeks must be')->assertFailed();
    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--weeks' => 'lots'])->expectsOutputToContain('--weeks must be')->assertFailed();
    $this->artisan('portal:demo-history', ['unit' => 'A/1-7', '--as' => 'Nobody.X'])->expectsOutputToContain("'Nobody.X' is not exactly one Admin")->assertFailed();

    $this->admin->forceFill(['is_admin' => false])->save();
    $this->artisan('portal:demo-history', ['unit' => 'A/1-7'])->expectsOutputToContain('Run php artisan portal:admin')->assertFailed();

    expect(SectionPractice::query()->count())->toBe(0);
});
