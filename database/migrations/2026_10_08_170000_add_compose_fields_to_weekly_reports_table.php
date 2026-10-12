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
        // The structured parts of a leader's report (5.5): saved with the draft and frozen with
        // the row on send, exactly like `notes`. Both nullable: a draft starts empty, and a
        // non-leaf report has no troopers of its own to account for.
        Schema::table('weekly_reports', function (Blueprint $table) {
            // One line naming what the section trained — the report's headline.
            $table->string('training_subject')->nullable()->after('notes');
            // `[{trooper_id, reason}]`: why each trooper who missed practice without a makeup
            // missed it. `reason` is an `App\Models\UnaccountedReason` value.
            $table->json('unaccounted')->nullable()->after('training_subject');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropColumn(['training_subject', 'unaccounted']);
        });
    }
};
