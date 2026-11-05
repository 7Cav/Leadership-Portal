<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a leader's confirmation says (#634): held (false, every confirmation made before this column) or not held.
     * Only read while `confirmed_at` is set; a mark edit that clears the confirmation clears it too.
     */
    public function up(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->boolean('not_held')->default(false)->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropColumn('not_held');
        });
    }
};
