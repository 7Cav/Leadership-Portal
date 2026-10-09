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
        // Demo history (#115): the explicit, tagged demo writer (`DemoHistoryRepository`) sets this
        // on every row it creates, so generated history never mixes silently with real records and
        // a purge deletes exactly those rows. Every other writer leaves the default `false`.
        // Indexed: purge and the real-data guard filter on it per subtree.
        foreach (['section_practices', 'attendance', 'weekly_reports'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('is_demo')->default(false)->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['section_practices', 'attendance', 'weekly_reports'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['is_demo']);
                $table->dropColumn('is_demo');
            });
        }
    }
};
