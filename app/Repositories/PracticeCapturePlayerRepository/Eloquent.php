<?php

namespace App\Repositories\PracticeCapturePlayerRepository;

use App\Models\GamePlatform;
use App\Models\PracticeCapturePlayer;
use App\Repositories\PracticeCapturePlayerRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements PracticeCapturePlayerRepository
{
    /**
     * The length of `practice_capture_players.player_name` (Laravel's default `string`, `varchar(255)`). The name
     * is whatever the game server reports, and a strict MySQL refuses a longer value outright, which would fail
     * the whole capture run, so nothing longer is ever sent. The player is keyed on `platform_id`, so a cut name
     * still resolves.
     */
    private const NAME_LENGTH = 255;

    public function replaceForPractice(int $practiceId, GamePlatform $platform, array $players): void
    {
        DB::transaction(function () use ($practiceId, $platform, $players) {
            PracticeCapturePlayer::query()->where('section_practice_id', $practiceId)->delete();

            foreach (collect($players)->unique('platform_id') as $player) {
                PracticeCapturePlayer::query()->create([
                    'section_practice_id' => $practiceId,
                    'platform' => $platform,
                    'player_name' => mb_substr($player['player_name'], 0, self::NAME_LENGTH),
                    'platform_id' => $player['platform_id'],
                    'trooper_id' => $player['trooper_id'],
                ]);
            }
        });
    }

    public function unmatchedFor(int $practiceId): Collection
    {
        return PracticeCapturePlayer::query()
            ->where('section_practice_id', $practiceId)
            ->whereNull('trooper_id')
            ->whereNotExists(fn ($query) => $query->from('game_player_dismissals')
                ->whereColumn('game_player_dismissals.platform', 'practice_capture_players.platform')
                ->whereColumn('game_player_dismissals.platform_id', 'practice_capture_players.platform_id'))
            ->whereNotExists(fn ($query) => $query->from('trooper_game_identities')
                ->whereColumn('trooper_game_identities.platform', 'practice_capture_players.platform')
                ->whereColumn('trooper_game_identities.platform_id', 'practice_capture_players.platform_id'))
            ->orderBy('player_name')
            ->orderBy('id')
            ->get();
    }

    public function findUnmatched(int $practiceId, GamePlatform $platform, string $platformId): ?PracticeCapturePlayer
    {
        return PracticeCapturePlayer::query()
            ->where('section_practice_id', $practiceId)
            ->where('platform', $platform->value)
            ->where('platform_id', $platformId)
            ->whereNull('trooper_id')
            ->first();
    }
}
