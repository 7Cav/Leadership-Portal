<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    public function down(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->enum('platform', ['hll', 'arma'])->change();
        });
    }
};
