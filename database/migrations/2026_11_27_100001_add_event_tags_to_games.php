<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tags the 7Cav calendar's titles mark each known game's events with, by slug, as they read on 14NOV26:
     * `[HLL] Operation Trenched n' Tuesday`, `[HLLV] Operation Tunnel Trap Tuesday`, `Arma Reforger | ACE | ...`,
     * `Wardogs | Wardogs Saturday's`, `SQUAD | Squad Saturday @1900Z`. Kept here, not read from the model, so the
     * migration never changes with it.
     */
    private const KNOWN = [
        'hll' => ['[HLL]'],
        'hll-vietnam' => ['[HLLV]', '[HLL:Vietnam]'],
        'arma' => ['Arma Reforger'],
        'wardogs' => ['Wardogs'],
        'squad' => ['SQUAD |'],
    ];

    /**
     * The calendar title tags of each game (#751): text in an event's title, any case, that marks the event as the
     * game's, so a section's roll call lists the events of the game it plays. Edited on Settings > Games. Null for a game
     * with none, which lists no events.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('event_tags')->nullable()->after('interface');
        });

        foreach (self::KNOWN as $slug => $tags) {
            DB::table('games')->where('slug', $slug)->update(['event_tags' => json_encode($tags)]);
        }
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('event_tags');
        });
    }
};
