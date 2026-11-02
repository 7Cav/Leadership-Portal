<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who added a unit by hand on Org structure, so they may undo the add within the hour while it is still empty.
     * Null for every unit the import or a migration made. `nullOnDelete`: an account going away never holds a unit.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('lead_position_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
