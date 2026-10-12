<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 7.9: a capture run names the practice it ran for, so the rollcall screen can show
        // when that practice's capture was last attempted and whether it failed (AD-20). Null for
        // every other source. No FK: like `triggered_by`, a run is a log row and never blocks on
        // (or is cascaded away with) the thing it describes.
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('section_practice_id')->nullable()->after('triggered_by');
            $table->index(['section_practice_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['section_practice_id', 'source']);
            $table->dropColumn('section_practice_id');
        });
    }
};
