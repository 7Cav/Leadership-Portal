<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the game server's presence data said about a section practice report when it was sent, frozen with it:
     * `presence_check` is `conflict` (a capture exists for a picked practice, and none of the picked captures saw
     * the staff member), `confirmed` (one did) or `no_data` (no picked practice was captured). `presence_source`
     * names the system the data came from as shown on screen ("CRCON" for Hell Let Loose). Null for "No", an FTX, an
     * office hour and every report sent before the check existed.
     */
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('presence_check', 16)->nullable()->after('notes');
            $table->string('presence_source', 32)->nullable()->after('presence_check');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn(['presence_check', 'presence_source']);
        });
    }
};
