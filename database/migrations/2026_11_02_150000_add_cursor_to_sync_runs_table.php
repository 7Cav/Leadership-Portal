<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an LOA sync got to, so the next run carries on from there instead of starting over: how far back each
        // section's first read of the year has reached, how far forward the new posts have been read, and the threads
        // to try again. Saved as the run goes, so a run the worker kills still keeps what it did. Null for every other
        // source, and for a full LOA run, which reads its own window.
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->json('cursor')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('cursor');
        });
    }
};
