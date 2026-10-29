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
        // The unit the org-structure import last placed this billet in. While `unit_id` still equals it, the row is the
        // import's own and a later import repoints it when Cav moves the billet. Once they differ, someone corrected it
        // by hand and the import leaves it alone. Null on a row the import never wrote or never confirmed (every row
        // stored before this column), which the import adopts only once Cav agrees with where it points.
        Schema::table('unit_billet_mappings', function (Blueprint $table) {
            $table->foreignId('imported_unit_id')->nullable()->after('unit_id')->constrained('units')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_billet_mappings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_unit_id');
        });
    }
};
