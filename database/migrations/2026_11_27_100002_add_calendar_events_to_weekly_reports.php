<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The week's calendar events as a section's roll call listed them when its report was sent (#751), so the post keeps
     * them after the calendar changes. Null for a report sent before this column, and for a platoon's or company's.
     */
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->json('calendar_events')->nullable()->after('makeup_checks');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropColumn('calendar_events');
        });
    }
};
