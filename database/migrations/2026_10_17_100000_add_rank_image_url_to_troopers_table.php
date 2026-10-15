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
        // The rank insignia image Cav serves for the trooper's rank (`https://7cav.us/data/roster_ranks/0/12.jpg`), which
        // the rollcall template prints as `{{rank_icon}}` (3.10). A synced display value, overwritten on every roster sync
        // like `rank`/`rank_short`; nullable because Cav may leave it out, and because only an https URL on 7cav.us is
        // kept (it is written into the forum post's [IMG] tag), so anything else is stored as null.
        Schema::table('troopers', function (Blueprint $table) {
            $table->string('rank_image_url')->nullable()->after('rank_short');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropColumn('rank_image_url');
        });
    }
};
