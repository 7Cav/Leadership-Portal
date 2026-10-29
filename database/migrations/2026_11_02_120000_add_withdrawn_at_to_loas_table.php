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
        // When a complete full LOA sync last looked for the thread in the window it read and did not find it: deleted,
        // hidden or moved out of the leave sections. The row is kept as history, but coverage ignores it from then on.
        // Cleared again when a later run sees the thread, so a thread put back counts again.
        Schema::table('loas', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('posted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loas', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
        });
    }
};
