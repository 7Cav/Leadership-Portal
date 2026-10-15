<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Story 7.10: WARDOGS identities (platform_id = SteamID64). Widens the enum to mirror
     * `App\Models\GamePlatform`; the unique keys are per platform, so the same Steam id can be
     * linked for HLL and WARDOGS.
     */
    public function up(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->enum('platform', ['hll', 'arma', 'wardogs'])->change();
        });
    }

    /**
     * Refused while any WARDOGS identity exists: the narrower enum cannot hold those rows (MySQL in
     * strict mode fails the ALTER), and deleting identities a leader linked is not a rollback's call.
     */
    public function down(): void
    {
        $linked = DB::table('trooper_game_identities')->where('platform', 'wardogs')->count();

        if ($linked > 0) {
            throw new RuntimeException("Cannot roll back the WARDOGS platform: {$linked} trooper_game_identities row(s) still use it. Unlink or delete them first, then roll back again.");
        }

        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->enum('platform', ['hll', 'arma'])->change();
        });
    }
};
