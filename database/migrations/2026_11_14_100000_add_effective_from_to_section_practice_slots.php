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
        // A slot set while the current week is already held (its report sent or its practice captured) starts with the
        // next Cav week: `effective_from` is that week's Saturday, and the weeks before it keep the slot they had
        // (`previous_*`, null when there was none). Null `effective_from` means the slot applies to every week.
        Schema::table('section_practice_slots', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('start_minute');
            $table->unsignedTinyInteger('previous_weekday')->nullable()->after('effective_from');
            $table->unsignedSmallInteger('previous_start_minute')->nullable()->after('previous_weekday');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_practice_slots', function (Blueprint $table) {
            $table->dropColumn(['effective_from', 'previous_weekday', 'previous_start_minute']);
        });
    }
};
