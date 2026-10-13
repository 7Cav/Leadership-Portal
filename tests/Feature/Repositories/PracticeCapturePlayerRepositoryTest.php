<?php

use App\Models\GamePlatform;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\GamePlayerDismissalRepository;
use App\Repositories\PracticeCapturePlayerRepository;

// 7.7: what a capture left for review, and the dismissals that hide players from it.

beforeEach(function () {
    $this->repo = app(PracticeCapturePlayerRepository::class);
    $this->dismissals = app(GamePlayerDismissalRepository::class);
    $this->practice = SectionPractice::factory()->create();
});

function playerRow(string $id, string $name = 'P', ?int $trooperId = null): array
{
    return ['platform_id' => $id, 'player_name' => $name, 'trooper_id' => $trooperId];
}

it('replaces a practice\'s players on every run and leaves other practices alone', function () {
    $other = SectionPractice::factory()->create();
    $this->repo->replaceForPractice($other->id, GamePlatform::Hll, [playerRow('z')]);
    $this->repo->replaceForPractice($this->practice->id, GamePlatform::Hll, [playerRow('a'), playerRow('b')]);
    $this->repo->replaceForPractice($this->practice->id, GamePlatform::Hll, [playerRow('c'), playerRow('c')]);

    expect($this->repo->unmatchedFor($this->practice->id)->pluck('platform_id')->all())->toBe(['c'])
        ->and($this->repo->unmatchedFor($other->id))->toHaveCount(1);
});

it('lists only unmatched players that are neither dismissed nor linked since, by name', function () {
    $trooper = Trooper::factory()->create();
    $this->repo->replaceForPractice($this->practice->id, GamePlatform::Hll, [
        playerRow('1', 'Zed'), playerRow('2', 'Alpha'), playerRow('3', 'Dismissed'), playerRow('4', 'Linked'), playerRow('5', 'Elsewhere', $trooper->id),
    ]);
    $this->dismissals->dismiss(GamePlatform::Hll, '3', 'Dismissed', User::factory()->create()->id);
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'platform' => GamePlatform::Hll, 'platform_id' => '4']);

    expect($this->repo->unmatchedFor($this->practice->id)->pluck('player_name')->all())->toBe(['Alpha', 'Zed']);
});

it('keeps a dismissal per platform, so the same id on another game is not hidden', function () {
    $this->repo->replaceForPractice($this->practice->id, GamePlatform::Arma, [playerRow('1')]);
    $this->dismissals->dismiss(GamePlatform::Hll, '1', 'P', User::factory()->create()->id);

    expect($this->repo->unmatchedFor($this->practice->id))->toHaveCount(1)
        ->and($this->dismissals->dismissedAmong(GamePlatform::Hll, ['1', '2']))->toBe(['1'])
        ->and($this->dismissals->forget(GamePlatform::Hll, '1'))->toBeTrue()
        ->and($this->dismissals->forget(GamePlatform::Hll, '1'))->toBeFalse();
});

it('finds a stored unmatched player only for its own practice and platform', function () {
    $this->repo->replaceForPractice($this->practice->id, GamePlatform::Hll, [playerRow('1')]);

    expect($this->repo->findUnmatched($this->practice->id, GamePlatform::Hll, '1'))->not->toBeNull()
        ->and($this->repo->findUnmatched($this->practice->id, GamePlatform::Arma, '1'))->toBeNull()
        ->and($this->repo->findUnmatched(SectionPractice::factory()->create()->id, GamePlatform::Hll, '1'))->toBeNull();
});
