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
        // The approved training plan that wrote the topic (#380), or null for one a leader set by hand. A planned week
        // then changes only through its plan, and a revision that leaves a week blank can clear what an earlier
        // approval of the same plan wrote there. `nullOnDelete`: plans are not deleted, and a topic outlives its source.
        Schema::table('platoon_training_topics', function (Blueprint $table) {
            $table->foreignId('training_plan_id')->nullable()->after('enforced')->constrained('training_plans')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platoon_training_topics', fn (Blueprint $table) => $table->dropConstrainedForeignId('training_plan_id'));
    }
};
