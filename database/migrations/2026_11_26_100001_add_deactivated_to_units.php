<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an Admin deactivated a unit on Org structure, and who. A unit with `deactivated_at` set, and everything under
     * it, is left out of every page that lists units; reactivating clears both. `nullOnDelete`: an account going away
     * never holds a unit.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('created_by');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn('deactivated_at');
        });
    }
};
