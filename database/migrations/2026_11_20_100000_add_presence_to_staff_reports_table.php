<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The staff report's presence question, apart from accountability: `presence_type` is `public_server` (an hour on
     * the public server), `office_hour` (an hour of office hours in Discord voice, with the existing `office_hour_date`
     * and `office_hour_note`) or `none`. `public_server_check` is what the game servers marked public said about a
     * public server hour, frozen at send: `confirmed` (60 minutes or more that Cav week), `conflict` (under) or
     * `no_data`. Null on a report sent before the question was asked, and the check is null for any other answer.
     */
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('presence_type', 16)->nullable()->after('office_hour_note');
            $table->string('public_server_check', 16)->nullable()->after('presence_source');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn(['presence_type', 'public_server_check']);
        });
    }
};
