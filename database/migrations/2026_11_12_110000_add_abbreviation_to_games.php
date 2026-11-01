<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The short codes of the games the migrations create, by slug. */
    private const KNOWN = ['hll' => 'HLL', 'hll-vietnam' => 'HLLV', 'arma' => 'ARMA', 'wardogs' => 'WD', 'squad' => 'SQD'];

    /**
     * A short code per game (2 to 6 uppercase letters or digits, unique), shown as the game chip on a battalion's row of
     * the org tree. Known games get theirs; any other is made from the name's initials, as `Game::abbreviationFor()`
     * does for a new game, and kept unique with a digit. The column stays nullable so an older migration's raw insert
     * (re-run on a rollback) still works; a game saved through the model always gets one (`Game::booted()`), and a
     * NULL never collides with the unique index.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('abbreviation', 6)->nullable()->after('name');
        });

        $taken = [];
        foreach (DB::table('games')->orderBy('id')->get(['id', 'slug', 'name']) as $game) {
            $base = self::KNOWN[$game->slug] ?? self::fromName($game->name);
            $code = $base;
            for ($n = 2; in_array($code, $taken, true); $n++) {
                $code = substr($base, 0, 6 - strlen((string) $n)).$n;
            }
            $taken[] = $code;
            DB::table('games')->where('id', $game->id)->update(['abbreviation' => $code]);
        }

        Schema::table('games', function (Blueprint $table) {
            $table->unique('abbreviation');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique(['abbreviation']);
            $table->dropColumn('abbreviation');
        });
    }

    /** Kept here, not read from the model, so the migration never changes with it. */
    private static function fromName(string $name): string
    {
        $words = preg_split('/[^A-Z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['GAME'];
        $code = count($words) > 1
            ? implode('', array_map(fn (string $word) => $word[0], $words))
            : $words[0][0].preg_replace('/[AEIOU]/', '', substr($words[0], 1));

        $code = substr($code, 0, 6);

        return strlen($code) >= 2 ? $code : substr($words[0].'X', 0, 2);
    }
};
