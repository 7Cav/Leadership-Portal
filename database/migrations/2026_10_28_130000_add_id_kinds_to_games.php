<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #428: a game's player id is no longer one label (`games.id_label`) but a list of kinds (`games.id_kinds`, a JSON
     * array of names such as ["Steam ID", "Epic ID", "Xbox ID"]), the first being the game's default. An identity
     * remembers which kind it was entered as (`trooper_game_identities.id_kind`, one of its game's kinds). The kinds
     * are the names a leader sees, so there is no separate key to keep in step; removing a kind an identity uses is
     * refused by `UpdateGame`.
     *
     * Every game starts with its old label as its one kind, and every existing identity takes its game's first kind,
     * so a game with one kind behaves exactly as before. The `(game_id, platform_id)` unique key stays as it is: one
     * human is never linked twice on a game, whichever kind they were entered as.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('id_kinds')->nullable()->after('name');
        });

        foreach (DB::table('games')->get(['id', 'id_label']) as $game) {
            DB::table('games')->where('id', $game->id)->update(['id_kinds' => json_encode([$game->id_label])]);
        }

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('id_label');
        });

        // SQLite rebuilds a table to change a column, and the games table cannot be rebuilt while identities point at it.
        // The Models and Commands never write a game without kinds, so the tests lose only the database's own check.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('games', function (Blueprint $table) {
                $table->json('id_kinds')->nullable(false)->change();
            });
        }

        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->string('id_kind', 50)->nullable()->after('platform_id');
        });

        foreach (DB::table('games')->get(['id', 'id_kinds']) as $game) {
            DB::table('trooper_game_identities')->where('game_id', $game->id)->update(['id_kind' => json_decode($game->id_kinds, true)[0]]);
        }

        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->string('id_kind', 50)->nullable(false)->change();
        });
    }

    /**
     * Back to one label per game: the first kind. A game's other kinds are dropped, and the identities that used them
     * stay linked, so a rollback after a game was given several kinds loses only the kind names.
     */
    public function down(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->dropColumn('id_kind');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->string('id_label')->nullable()->after('name');
        });

        foreach (DB::table('games')->get(['id', 'id_kinds']) as $game) {
            DB::table('games')->where('id', $game->id)->update(['id_label' => json_decode($game->id_kinds, true)[0]]);
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('games', function (Blueprint $table) {
                $table->string('id_label')->nullable(false)->change();
            });
        }

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('id_kinds');
        });
    }
};
