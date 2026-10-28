<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a counseling record is for (`App\Models\CounselingKind`): a missed section practice or rollcall, or other
     * conduct, so the panel applies the right ladder of the regulations. Portal-owned (AD-22), set once when the record
     * is filed. Records filed before this column existed stay null and are ruled as other conduct.
     */
    public function up(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->enum('kind', ['missed_practice', 'conduct'])->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
