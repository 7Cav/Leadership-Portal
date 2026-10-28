<?php

use App\Authorization\ScopeResolver;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\GameIdentitySectionSeeder;

// The game identities browser spec seeds one of these before every test, so each test starts clean and none shares
// links with another, whichever worker runs it.

afterEach(function () {
    unset($_SERVER['E2E_SECTION_LEADER'], $_ENV['E2E_SECTION_LEADER']);
});

function seedSectionFor(string $leader): Unit
{
    $_SERVER['E2E_SECTION_LEADER'] = $leader;
    app(GameIdentitySectionSeeder::class)->run();

    return Unit::query()->where('callsign', "S/{$leader}")->sole();
}

it('seeds a section of eight troopers linked to no game, under a leader who can write it', function () {
    $section = seedSectionFor('Lead0001.G');
    $leader = User::query()->where('username', 'Lead0001.G')->sole();

    expect($section->parent_id)->toBeNull()
        ->and(Trooper::query()->where('primary_section_id', $section->id)->count())->toBe(GameIdentitySectionSeeder::STRENGTH)
        ->and(TrooperGameIdentity::query()->count())->toBe(0)
        ->and(app(ScopeResolver::class)->writeScope($leader)->values()->all())->toBe([$section->id]);
});

it('gives every run a section and leader of its own', function () {
    $first = seedSectionFor('Lead0001.G');
    $second = seedSectionFor('Lead0002.G');

    expect($second->id)->not->toBe($first->id)
        ->and(Trooper::query()->where('primary_section_id', $second->id)->count())->toBe(GameIdentitySectionSeeder::STRENGTH)
        ->and(app(ScopeResolver::class)->writeScope(User::query()->where('username', 'Lead0002.G')->sole())->values()->all())->toBe([$second->id]);
});

it('refuses to seed without a leader to name', function () {
    expect(fn () => app(GameIdentitySectionSeeder::class)->run())->toThrow(RuntimeException::class, 'Set E2E_SECTION_LEADER');

    expect(Unit::count())->toBe(0);
});
