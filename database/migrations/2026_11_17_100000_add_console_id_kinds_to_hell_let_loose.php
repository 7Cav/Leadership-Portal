<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The kinds each Hell Let Loose game gains, by slug: PlayStation players on both, the EOS ID on Vietnam. */
    private const ADDED = [
        'hll' => ['PlayStation ID'],
        'hll-vietnam' => ['PlayStation ID', 'EOS ID'],
    ];

    /**
     * PlayStation players have their own ID on both Hell Let Loose games, and Vietnam's servers also report an EOS ID
     * (one per game, so it is never shared between the two). Each kind is appended when the game does not already ask
     * for it, so the default kind, an Admin's own kinds and their order stay as they are, and a second run changes
     * nothing. A game that is not there is skipped.
     */
    public function up(): void
    {
        foreach (self::ADDED as $slug => $kinds) {
            $game = DB::table('games')->where('slug', $slug)->first(['id', 'id_kinds']);

            if ($game === null) {
                continue;
            }

            $current = json_decode((string) $game->id_kinds, true) ?: [];
            $missing = array_values(array_diff($kinds, $current));

            if ($missing !== []) {
                DB::table('games')->where('id', $game->id)->update(['id_kinds' => json_encode([...$current, ...$missing]), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Removes the kinds again, but only a kind no identity on that game uses: an identity is never left with a kind
     * its game no longer asks for. A kind in use stays on its game.
     */
    public function down(): void
    {
        foreach (self::ADDED as $slug => $kinds) {
            $game = DB::table('games')->where('slug', $slug)->first(['id', 'id_kinds']);

            if ($game === null) {
                continue;
            }

            $used = DB::table('trooper_game_identities')->where('game_id', $game->id)->distinct()->pluck('id_kind')->all();
            $current = json_decode((string) $game->id_kinds, true) ?: [];
            $kept = array_values(array_filter($current, fn (string $kind) => ! in_array($kind, $kinds, true) || in_array($kind, $used, true)));

            if ($kept !== $current && $kept !== []) {
                DB::table('games')->where('id', $game->id)->update(['id_kinds' => json_encode($kept), 'updated_at' => now()]);
            }
        }
    }
};
