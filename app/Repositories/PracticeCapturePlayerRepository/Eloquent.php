<?php

namespace App\Repositories\PracticeCapturePlayerRepository;

use App\Models\GamePlatform;
use App\Models\PracticeCapturePlayer;
use App\Repositories\PracticeCapturePlayerRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements PracticeCapturePlayerRepository
{
    public function replaceForPractice(int $practiceId, GamePlatform $platform, array $players): void
    {
        DB::transaction(function () use ($practiceId, $platform, $players) {
            PracticeCapturePlayer::query()->where('section_practice_id', $practiceId)->delete();

            foreach (collect($players)->unique('platform_id') as $player) {
                PracticeCapturePlayer::query()->create([
                    'section_practice_id' => $practiceId,
                    'platform' => $platform,
                    'player_name' => $player['player_name'],
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
