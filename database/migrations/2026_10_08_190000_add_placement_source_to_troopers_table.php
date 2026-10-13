<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Who set `primary_section_id` (2.7): the roster sync's billet mapping, or an Admin's audited
        // manual placement. Null while the trooper is unplaced. The sync's mapping step only ever
        // writes the troopers it can resolve, so a manual placement survives a run that still cannot
        // resolve them; once Cav does resolve them, the mapping takes over and the source flips to
        // `sync` (the source of truth wins, and the rule cannot flip-flop between runs).
        Schema::table('troopers', function (Blueprint $table) {
            $table->enum('placement_source', ['sync', 'manual'])->nullable()->after('primary_section_id');
        });

        // Every placement that exists before this column came from the sync's mapping (2.5).
        DB::table('troopers')->whereNotNull('primary_section_id')->update(['placement_source' => 'sync']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropColumn('placement_source');
        });
    }
};
