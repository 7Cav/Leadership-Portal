<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The trooper's primary Cav billet (`positionId`, 2.5), synced like `rank`/`mos` and
        // overwritten on every roster sync — the key ScopeResolver joins through
        // `unit_command_mappings` to derive a leader's command scope (1.16, AD-7). Nullable: Cav
        // may report no usable billet for a row (2.1's per-field degrade). Not a foreign key — a
        // billet is a Cav identifier, not a portal row.
        Schema::table('troopers', function (Blueprint $table) {
            $table->unsignedBigInteger('cav_position_id')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropColumn('cav_position_id');
        });
    }
};
