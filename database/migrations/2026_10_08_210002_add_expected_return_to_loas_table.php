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
        // An ELOA report names an expected return date. It is only an expectation (an ELOA can be extended, and
        // ends when a return is posted), so it never becomes `ends_at` and never decides coverage: it is kept
        // beside it, for display. Null for an LOA, and for an ELOA whose date could not be read.
        Schema::table('loas', function (Blueprint $table) {
            $table->date('expected_return_at')->nullable()->after('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loas', function (Blueprint $table) {
            $table->dropColumn('expected_return_at');
        });
    }
};
