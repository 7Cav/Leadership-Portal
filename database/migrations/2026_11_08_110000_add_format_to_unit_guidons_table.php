<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guidon formats: how the flag is split (`diagonal`, `horizontal` or `solid`), where each text sits on its half
     * (`left` or `center`, used by the top and bottom formats), and an optional large character at the hoist that spans
     * both halves. The defaults are what every guidon saved before this drew, so no row needs a backfill.
     */
    public function up(): void
    {
        Schema::table('unit_guidons', function (Blueprint $table) {
            $table->string('format', 12)->default('diagonal')->after('unit_id');
            $table->string('top_align', 6)->default('left')->after('bottom_right_text');
            $table->string('bottom_align', 6)->default('left')->after('top_align');
            $table->string('large_text', 2)->default('')->after('bottom_align');
        });
    }

    public function down(): void
    {
        Schema::table('unit_guidons', function (Blueprint $table) {
            $table->dropColumn(['format', 'top_align', 'bottom_align', 'large_text']);
        });
    }
};
