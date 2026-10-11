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
        // When the thread was posted on the forum, as the forum says. A row that needs review asserts no dates,
        // so this is the one date it does have: coverage (4.4) shields the trooper for the Cav week the leave was
        // posted in and the week after, then stops, so a broken post cannot keep someone out of the KPIs for
        // good. `created_at` is not that date: it is when the portal first stored the row, which for every thread
        // the first sync backfills is the day of that sync.
        //
        // Nullable: rows stored before this column existed have none until the sync reads their thread again.
        // A row that needs review is never settled history, so the daily full sync re-reads, and fills, every
        // one of those within a day.
        Schema::table('loas', function (Blueprint $table) {
            $table->timestamp('posted_at')->nullable()->after('source_thread');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loas', function (Blueprint $table) {
            $table->dropColumn('posted_at');
        });
    }
};
