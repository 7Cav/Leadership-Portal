<?php

namespace App\Repositories\PracticeCapturePlayerRepository;

use App\Models\Game;
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

    public function replaceForPractice(int $practiceId, Game $game, array $players): void
    {
        DB::transaction(function () use ($practiceId, $game, $players) {
            PracticeCapturePlayer::query()->where('section_practice_id', $practiceId)->delete();

            // Without case, as the unique key compares on MySQL: two spellings of one ID would fail the whole write.
            foreach (collect($players)->unique(fn (array $player) => mb_strtolower($player['platform_id'])) as $player) {
                PracticeCapturePlayer::query()->create([
                    'section_practice_id' => $practiceId,
                    'game_id' => $game->id,
                    'player_name' => mb_substr($player['player_name'], 0, self::NAME_LENGTH),
                    'platform_id' => $player['platform_id'],
                    'trooper_id' => $player['trooper_id'],
                ]);
            }
        });
    }

    public function unmatchedFor(int $practiceId): Collection
    {
        return PracticeCapturePlayer::query()->with('game')
            ->where('section_practice_id', $practiceId)
            ->whereNull('trooper_id')
            ->whereNotExists(fn ($query) => $query->from('game_player_dismissals')
                ->whereColumn('game_player_dismissals.game_id', 'practice_capture_players.game_id')
                ->whereColumn('game_player_dismissals.platform_id', 'practice_capture_players.platform_id'))
            ->whereNotExists(fn ($query) => $query->from('trooper_game_identities')
                ->whereColumn('trooper_game_identities.game_id', 'practice_capture_players.game_id')
                ->whereColumn('trooper_game_identities.platform_id', 'practice_capture_players.platform_id'))
            ->orderBy('player_name')
            ->orderBy('id')
            ->get();
    }

    public function dismissedFor(int $practiceId): Collection
    {
        return PracticeCapturePlayer::query()->with('game')
            ->where('section_practice_id', $practiceId)
            ->whereNull('trooper_id')
            ->whereExists(fn ($query) => $query->from('game_player_dismissals')
                ->whereColumn('game_player_dismissals.game_id', 'practice_capture_players.game_id')
                ->whereColumn('game_player_dismissals.platform_id', 'practice_capture_players.platform_id'))
            ->whereNotExists(fn ($query) => $query->from('trooper_game_identities')
                ->whereColumn('trooper_game_identities.game_id', 'practice_capture_players.game_id')
                ->whereColumn('trooper_game_identities.platform_id', 'practice_capture_players.platform_id'))
            ->orderBy('player_name')
            ->orderBy('id')
            ->get();
    }

    public function findUnmatched(int $practiceId, Game $game, string $platformId): ?PracticeCapturePlayer
    {
        return PracticeCapturePlayer::query()->with('game')
            ->where('section_practice_id', $practiceId)
            ->where('game_id', $game->id)
            ->where('platform_id', $platformId)
            ->whereNull('trooper_id')
            ->first();
    }

    public function seenTrooper(int $trooperId): Collection
    {
        return PracticeCapturePlayer::query()
            ->with('practice.section')
            ->join('section_practices', 'section_practices.id', '=', 'practice_capture_players.section_practice_id')
            ->where('practice_capture_players.trooper_id', $trooperId)
            ->orderByDesc('section_practices.week_start')
            ->orderBy('practice_capture_players.id')
            ->select('practice_capture_players.*')
            ->get()
            ->unique('section_practice_id')
            ->values()
            ->toBase();
    }
}
