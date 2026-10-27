<?php

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
    $this->repo->replaceForPractice($other->id, game('hll'), [playerRow('z')]);
    $this->repo->replaceForPractice($this->practice->id, game('hll'), [playerRow('a'), playerRow('b')]);
    $this->repo->replaceForPractice($this->practice->id, game('hll'), [playerRow('c'), playerRow('c')]);

    expect($this->repo->unmatchedFor($this->practice->id)->pluck('platform_id')->all())->toBe(['c'])
        ->and($this->repo->unmatchedFor($other->id))->toHaveCount(1);
});

it('lists only unmatched players that are neither dismissed nor linked since, by name', function () {
    $trooper = Trooper::factory()->create();
    $this->repo->replaceForPractice($this->practice->id, game('hll'), [
        playerRow('1', 'Zed'), playerRow('2', 'Alpha'), playerRow('3', 'Dismissed'), playerRow('4', 'Linked'), playerRow('5', 'Elsewhere', $trooper->id),
    ]);
    $this->dismissals->dismiss(game('hll'), '3', 'Dismissed', User::factory()->create()->id);
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game('hll')->id, 'platform_id' => '4']);

    expect($this->repo->unmatchedFor($this->practice->id)->pluck('player_name')->all())->toBe(['Alpha', 'Zed']);
});

it('lists the practice\'s dismissed players that nobody linked since, by name, and only this practice\'s (#176)', function () {
    $trooper = Trooper::factory()->create();
    $by = User::factory()->create()->id;
    $this->repo->replaceForPractice($this->practice->id, game('hll'), [
        playerRow('1', 'Zed'), playerRow('2', 'Alpha'), playerRow('3', 'Open'), playerRow('4', 'Linked since'), playerRow('5', 'Elsewhere', $trooper->id),
    ]);
    foreach (['1' => 'Zed', '2' => 'Alpha', '4' => 'Linked since', '5' => 'Elsewhere', '9' => 'Never seen here'] as $id => $name) {
        $this->dismissals->dismiss(game('hll'), (string) $id, $name, $by);
    }
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game('hll')->id, 'platform_id' => '4']);

    expect($this->repo->dismissedFor($this->practice->id)->pluck('player_name')->all())->toBe(['Alpha', 'Zed'])
        ->and($this->repo->dismissedFor(SectionPractice::factory()->create()->id))->toHaveCount(0);

    $this->dismissals->forget(game('hll'), '2');
    expect($this->repo->dismissedFor($this->practice->id)->pluck('player_name')->all())->toBe(['Zed'])
        ->and($this->repo->unmatchedFor($this->practice->id)->pluck('player_name')->all())->toBe(['Alpha', 'Open']);
});

it('keeps a dismissal per platform, so the same id on another game is not hidden', function () {
    $this->repo->replaceForPractice($this->practice->id, game('arma'), [playerRow('1')]);
    $this->dismissals->dismiss(game('hll'), '1', 'P', User::factory()->create()->id);

    expect($this->repo->unmatchedFor($this->practice->id))->toHaveCount(1)
        ->and($this->dismissals->dismissedAmong(game('hll'), ['1', '2']))->toBe(['1'])
        ->and($this->dismissals->forget(game('hll'), '1'))->toBeTrue()
        ->and($this->dismissals->forget(game('hll'), '1'))->toBeFalse();
});

it('finds a stored unmatched player only for its own practice and platform', function () {
    $this->repo->replaceForPractice($this->practice->id, game('hll'), [playerRow('1')]);

    expect($this->repo->findUnmatched($this->practice->id, game('hll'), '1'))->not->toBeNull()
        ->and($this->repo->findUnmatched($this->practice->id, game('arma'), '1'))->toBeNull()
        ->and($this->repo->findUnmatched(SectionPractice::factory()->create()->id, game('hll'), '1'))->toBeNull();
});
