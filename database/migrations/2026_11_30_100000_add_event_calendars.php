<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 7Cav forum's calendar for each known game's operations, classes and FTXs, as the forum's calendar list read on
     * 16NOV26 (`/calendar/{id}/`), by game name in lower case. Kept here, not read from the model, so the migration
     * never changes with it; a game named otherwise is set on Settings > Games. The seeded games are found by slug too
     * ({@see SLUGS}), since their names were changed since (`Hell Let Loose (WW2)`, `Hell Let Loose: Vietnam`).
     */
    private const KNOWN = [
        'hell let loose' => ['operation' => [15], 'class' => [20], 'ftx' => [27]],
        'hell let loose vietnam' => ['operation' => [16], 'class' => [21], 'ftx' => [28]],
        'arma reforger' => ['operation' => [43], 'class' => [19], 'ftx' => []],
        'arma 3' => ['operation' => [14], 'class' => [], 'ftx' => [26]],
        'squad' => ['operation' => [34], 'class' => [33], 'ftx' => [35]],
        'ready or not' => ['operation' => [39], 'class' => [40], 'ftx' => [41]],
        'battlefield 6' => ['operation' => [46], 'class' => [38], 'ftx' => []],
        'dcs world' => ['operation' => [32], 'class' => [31], 'ftx' => [36]],
        'foxhole' => ['operation' => [48], 'class' => [47], 'ftx' => []],
        'wardogs' => ['operation' => [], 'class' => [49], 'ftx' => []],
    ];

    /** The seeded games' slugs, with the name in {@see KNOWN} each one is. */
    private const SLUGS = [
        'hll' => 'hell let loose',
        'hll-vietnam' => 'hell let loose vietnam',
        'arma' => 'arma reforger',
        'wardogs' => 'wardogs',
    ];

    /**
     * The forum calendars a game's events come from, by kind (operation, class, ftx), and the calendar each stored event
     * was read from. The forum keeps one calendar per game under Operations, Classes and FTX; the calendar sync reads each
     * one a game names, so an event is the game's by where it is filed, not by its title.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('event_calendars')->nullable()->after('event_tags');
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unsignedInteger('calendar_id')->nullable()->after('uid');
            $table->index('calendar_id');
        });

        foreach (DB::table('games')->get(['id', 'slug', 'name']) as $game) {
            $calendars = self::KNOWN[self::SLUGS[$game->slug] ?? mb_strtolower(trim((string) $game->name))] ?? null;
            if ($calendars !== null) {
                DB::table('games')->where('id', $game->id)->update(['event_calendars' => json_encode($calendars)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['calendar_id']);
            $table->dropColumn('calendar_id');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('event_calendars');
        });
    }
};
