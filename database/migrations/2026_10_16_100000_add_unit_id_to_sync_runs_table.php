<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An echelon roster sync (`roster-unit`) names the unit it ran for, so that unit's leader can see whether
        // their last sync worked. Null for every other source. No FK, like `section_practice_id`: a run is a log
        // row and never blocks on (or is cascaded away with) the thing it describes.
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable()->after('section_practice_id');
            $table->index(['unit_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['unit_id', 'source']);
            $table->dropColumn('unit_id');
        });
    }
};
