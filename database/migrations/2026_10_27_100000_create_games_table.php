<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables that named a game by a `platform` enum, with the unique keys that enum was part of: [name, columns]
     * before (with `platform`) and after (with `game_id`). The new keys get new names, because each is added before
     * the old one is dropped — MySQL will not drop a unique key that is the only index serving a foreign key
     * (`trooper_id`, `section_practice_id`) until another one leads with that column.
     *
     * @var array<string, array{enum: list<string>, keys: list<array{string, list<string>, string, list<string>}>}>
     */
    private const TABLES = [
        'trooper_game_identities' => [
            'enum' => ['hll', 'arma', 'wardogs'],
            'keys' => [
                ['trooper_game_identities_platform_platform_id_unique', ['platform', 'platform_id'], 'trooper_game_identities_game_platform_id_unique', ['game_id', 'platform_id']],
                ['trooper_game_identities_trooper_id_platform_unique', ['trooper_id', 'platform'], 'trooper_game_identities_trooper_game_unique', ['trooper_id', 'game_id']],
            ],
        ],
        'practice_capture_players' => [
            'enum' => ['hll', 'arma', 'wardogs'],
            'keys' => [
                ['practice_capture_players_unique', ['section_practice_id', 'platform', 'platform_id'], 'practice_capture_players_game_unique', ['section_practice_id', 'game_id', 'platform_id']],
            ],
        ],
        'game_player_dismissals' => [
            'enum' => ['hll', 'arma', 'wardogs'],
            'keys' => [
                ['game_player_dismissals_platform_platform_id_unique', ['platform', 'platform_id'], 'game_player_dismissals_game_platform_id_unique', ['game_id', 'platform_id']],
            ],
        ],
    ];

    /**
     * #388: the games an Admin keeps (AD-6), replacing the `platform` enum and `App\Models\GamePlatform`. Portal-owned
     * (AD-22): no source re-fetches it, so it belongs on the backup list. One writer, the audited `app/Commands/Games`
     * Commands through `GameRepository`. `interface` names which of the portal's built-in telemetry seams reads the
     * game's attendance (AD-3: the seams stay code, an Admin only picks one); `retired_at` set means the game can no
     * longer be newly linked or assigned, while what already uses it stays.
     *
     * The three games the enum held are created here with the names, id labels and interfaces `GamePlatform` gave
     * them, and every identity, capture player and dismissal is moved onto its game by `game_id`. The literals keep
     * this migration a frozen record of the data it moved.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            // Stable: routes and the rollcall's requests name a game by it, so a rename never changes it.
            $table->string('slug')->unique();
            $table->string('name');
            // What the game's player identifier is called to a leader ("Steam ID", "Player ID").
            $table->string('id_label');
            $table->string('interface');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('games')->insert([
            ['slug' => 'hll', 'name' => 'Hell Let Loose', 'id_label' => 'Steam ID', 'interface' => 'crcon', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'arma', 'name' => 'Arma Reforger', 'id_label' => 'Player ID', 'interface' => 'battlemetrics', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'wardogs', 'name' => 'WARDOGS', 'id_label' => 'Steam ID', 'interface' => 'nightwatch', 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (self::TABLES as $name => $spec) {
            Schema::table($name, function (Blueprint $table) {
                // `restrictOnDelete`: a game in use is retired, never deleted.
                $table->foreignId('game_id')->nullable()->after('id')->constrained('games')->restrictOnDelete();
            });

            DB::table($name)->update(['game_id' => DB::table('games')->select('id')->whereColumn('games.slug', "{$name}.platform")]);

            Schema::table($name, function (Blueprint $table) use ($spec) {
                $table->unsignedBigInteger('game_id')->nullable(false)->change();

                foreach ($spec['keys'] as [, , $newKey, $newColumns]) {
                    $table->unique($newColumns, $newKey);
                }
            });

            Schema::table($name, function (Blueprint $table) use ($spec) {
                foreach ($spec['keys'] as [$oldKey]) {
                    $table->dropUnique($oldKey);
                }

                $table->dropColumn('platform');
            });
        }
    }

    /**
     * Back to the `platform` enum. Refused while any row uses a game added since (the enum cannot hold it), before
     * anything is changed: deleting what a leader linked is not a rollback's call.
     */
    public function down(): void
    {
        $known = ['hll', 'arma', 'wardogs'];
        $stranded = 0;
        $slugs = [];

        foreach (array_keys(self::TABLES) as $name) {
            $rows = DB::table($name)->join('games', 'games.id', '=', "{$name}.game_id")->whereNotIn('games.slug', $known)->pluck('games.slug');
            $stranded += $rows->count();
            $slugs = [...$slugs, ...$rows->all()];
        }

        if ($stranded > 0) {
            $list = implode(', ', array_unique($slugs));

            throw new RuntimeException("Cannot roll back the games table: {$stranded} row(s) use a game the platform enum cannot hold ({$list}). Unlink or delete them first, then roll back again.");
        }

        foreach (self::TABLES as $name => $spec) {
            Schema::table($name, function (Blueprint $table) use ($spec) {
                $table->enum('platform', $spec['enum'])->nullable()->after('id');
            });

            DB::table($name)->update(['platform' => DB::table('games')->select('slug')->whereColumn('games.id', "{$name}.game_id")]);

            Schema::table($name, function (Blueprint $table) use ($spec) {
                $table->enum('platform', $spec['enum'])->nullable(false)->change();

                foreach ($spec['keys'] as [$oldKey, $oldColumns]) {
                    $table->unique($oldColumns, $oldKey);
                }
            });

            // The foreign key goes first: MySQL will not drop a unique key that is the only index serving it.
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['game_id']);
            });

            Schema::table($name, function (Blueprint $table) use ($spec) {
                foreach ($spec['keys'] as [, , $newKey]) {
                    $table->dropUnique($newKey);
                }

                $table->dropColumn('game_id');
            });
        }

        Schema::dropIfExists('games');
    }
};
