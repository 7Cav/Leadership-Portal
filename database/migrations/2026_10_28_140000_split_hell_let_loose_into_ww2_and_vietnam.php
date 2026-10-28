<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WW2_SLUG = 'hll';

    private const WW2_OLD_NAME = 'Hell Let Loose';

    private const WW2_NAME = 'Hell Let Loose (WW2)';

    private const VIETNAM_SLUG = 'hll-vietnam';

    private const VIETNAM_NAME = 'Hell Let Loose: Vietnam';

    /** What the two games ask for, Steam first: the existing WW2 identities are Steam IDs, so it stays the default. */
    private const KINDS = ['Steam ID', 'Epic ID', 'Xbox ID'];

    /** The tables that point at a game: where a Vietnam row would strand a rollback. */
    private const GAME_TABLES = ['trooper_game_identities', 'practice_capture_players', 'game_player_dismissals', 'game_servers'];

    /**
     * #431: Hell Let Loose WW2 and Hell Let Loose: Vietnam are two games to the regiment, with different player ids.
     * The existing `hll` game becomes "Hell Let Loose (WW2)" (its slug never changes), keeping every identity, capture
     * and dismissal, so past captures and attendance stay with WW2. A second game, `hll-vietnam`, is added beside it.
     * Both take the Steam, Epic and Xbox ID kinds (#428).
     *
     * Vietnam is created with interface `none`: how its attendance is read is not decided yet, so nothing is captured
     * for it until an Admin picks an interface on Settings > Games.
     *
     * Safe over what an Admin may already have done: a game already on the Vietnam slug is left as it is, a rename or
     * kinds an Admin already changed on WW2 are kept, and a name another game holds stops the migration with a message
     * before anything changes.
     */
    public function up(): void
    {
        foreach ([self::WW2_NAME => self::WW2_SLUG, self::VIETNAM_NAME => self::VIETNAM_SLUG] as $name => $slug) {
            $holder = DB::table('games')->where('name', $name)->value('slug');

            if ($holder !== null && $holder !== $slug) {
                throw new RuntimeException("Cannot split Hell Let Loose: the name \"{$name}\" already belongs to the game \"{$holder}\". Rename that game on Settings > Games, then migrate again.");
            }
        }

        $now = now();

        DB::table('games')->where('slug', self::WW2_SLUG)->where('name', self::WW2_OLD_NAME)->update(['name' => self::WW2_NAME, 'updated_at' => $now]);

        // Only when it still has the one kind it started with: kinds an Admin already set are theirs.
        $ww2 = DB::table('games')->where('slug', self::WW2_SLUG)->first(['id', 'id_kinds']);

        if ($ww2 !== null && json_decode($ww2->id_kinds, true) === ['Steam ID']) {
            DB::table('games')->where('id', $ww2->id)->update(['id_kinds' => json_encode(self::KINDS), 'updated_at' => $now]);
        }

        if (! DB::table('games')->where('slug', self::VIETNAM_SLUG)->exists()) {
            DB::table('games')->insert([
                'slug' => self::VIETNAM_SLUG,
                'name' => self::VIETNAM_NAME,
                'id_kinds' => json_encode(self::KINDS),
                'interface' => 'none',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Back to one Hell Let Loose. Refused while anything uses the Vietnam game, or another game holds the old name,
     * before anything changes: deleting what a leader linked is not a rollback's call. The Vietnam game is deleted
     * only while it is still the one `up()` made (same name, kinds and no interface); one an Admin added or changed
     * stays. WW2 takes its old name back, and its old single kind unless an identity already uses another.
     */
    public function down(): void
    {
        $holder = DB::table('games')->where('name', self::WW2_OLD_NAME)->value('slug');

        if ($holder !== null && $holder !== self::WW2_SLUG) {
            throw new RuntimeException('Cannot roll back the Hell Let Loose split: the name "'.self::WW2_OLD_NAME."\" already belongs to the game \"{$holder}\". Rename that game on Settings > Games, then roll back again.");
        }

        $vietnam = DB::table('games')->where('slug', self::VIETNAM_SLUG)->first(['id', 'name', 'id_kinds', 'interface']);

        if ($vietnam !== null) {
            foreach (self::GAME_TABLES as $table) {
                $used = DB::table($table)->where('game_id', $vietnam->id)->count();

                if ($used > 0) {
                    throw new RuntimeException("Cannot roll back the Hell Let Loose split: {$used} row(s) in {$table} use the Vietnam game. Unlink or delete them first, then roll back again.");
                }
            }

            if ($vietnam->name === self::VIETNAM_NAME && $vietnam->interface === 'none' && json_decode($vietnam->id_kinds, true) === self::KINDS) {
                DB::table('games')->where('id', $vietnam->id)->delete();
            }
        }

        $now = now();
        DB::table('games')->where('slug', self::WW2_SLUG)->where('name', self::WW2_NAME)->update(['name' => self::WW2_OLD_NAME, 'updated_at' => $now]);

        $ww2 = DB::table('games')->where('slug', self::WW2_SLUG)->first(['id', 'id_kinds']);

        if ($ww2 !== null
            && json_decode($ww2->id_kinds, true) === self::KINDS
            && ! DB::table('trooper_game_identities')->where('game_id', $ww2->id)->where('id_kind', '!=', 'Steam ID')->exists()) {
            DB::table('games')->where('id', $ww2->id)->update(['id_kinds' => json_encode(['Steam ID']), 'updated_at' => $now]);
        }
    }
};
