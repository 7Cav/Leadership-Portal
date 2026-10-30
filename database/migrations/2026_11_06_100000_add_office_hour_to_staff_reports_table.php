<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A battalion HQ staff member may report an hour of office hours in place of the hour of play: `practice_type`
     * `office_hour`, with the day it was held (`office_hour_date`, inside the Cav week) and a short note
     * (`office_hour_note`, at most 200 characters). Columns of their own rather than reusing the FTX date and the notes:
     * the notes stay the staff member's free text, and the sheet and the page read each field for what it is.
     * `practice_type` widens to fit `office_hour`. Null on every other report.
     */
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('practice_type', 16)->nullable()->change();
            $table->date('office_hour_date')->nullable()->after('ftx_date');
            $table->string('office_hour_note', 200)->nullable()->after('office_hour_date');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn(['office_hour_date', 'office_hour_note']);
            $table->string('practice_type', 8)->nullable()->change();
        });
    }
};
