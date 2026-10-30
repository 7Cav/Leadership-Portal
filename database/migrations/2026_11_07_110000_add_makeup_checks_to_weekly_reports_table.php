<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A section's report records the leader's answer for each trooper who asked for a make-up that week and had none
     * recorded when the report was sent: `[{trooper_id, done}]`, frozen with the report. Null on a report sent before
     * the question existed and on every roll-up.
     */
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->json('makeup_checks')->nullable()->after('unaccounted');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropColumn('makeup_checks');
        });
    }
};
