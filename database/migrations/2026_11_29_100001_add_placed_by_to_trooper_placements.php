<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Admin made a manual placement (`placed_by`) or ended one (`closed_by`, a unit deleted under the trooper), so
     * Placement changes names them. Null for the roster sync and for rows written before this. `nullOnDelete`: an
     * account going away keeps the history.
     */
    public function up(): void
    {
        Schema::table('trooper_placements', function (Blueprint $table) {
            $table->foreignId('placed_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->after('placed_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trooper_placements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('placed_by');
        });
    }
};
