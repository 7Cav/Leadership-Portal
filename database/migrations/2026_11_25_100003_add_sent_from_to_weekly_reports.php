<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a report's latest send started (#703): `rollcall` when the leader closed or sent it from the roll call,
     * `report` from the report page, null for a send before this column. It answers the issue's success check, how
     * many section reports are sent from the roll call, and changes nothing anyone reads.
     */
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->string('sent_from', 16)->nullable()->after('sent_by');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropColumn('sent_from');
        });
    }
};
