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
        // How many rows a run left for a person to look at (4.2: leave threads whose dates could not be
        // read cleanly, or whose trooper could not be found). Its own column rather than `errored`, which
        // makes a run `partial`: an unreadable post is an expected outcome, not a failed sync, and a
        // partial run would stop counting as the last good one.
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->unsignedInteger('needs_review')->default(0)->after('errored');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }
};
