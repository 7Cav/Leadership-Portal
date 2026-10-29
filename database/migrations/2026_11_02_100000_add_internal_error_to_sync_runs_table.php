<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A failed run that broke inside the portal (a database error, a bug) rather than on a source that could not
        // answer. The rollcall's telemetry banner reads it, so it never blames a game server that answered for the
        // portal's own error. False on every other run, and on every run from before it existed.
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->boolean('internal_error')->default(false)->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('internal_error');
        });
    }
};
