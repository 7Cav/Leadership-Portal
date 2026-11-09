<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a public server check found no data, frozen with it: `no_server` (no public server set up for the HQ's game),
     * `no_identity` (no game identity linked for it) or `unreadable` (servers set up, none could be read for minutes).
     * Null with any other check, and on a report checked before the reason was kept.
     */
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('public_server_reason', 16)->nullable()->after('public_server_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn('public_server_reason');
        });
    }
};
