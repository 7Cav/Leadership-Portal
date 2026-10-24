<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ELOA "was" (decided 2026-10-24): the line unit a trooper was placed in at the moment the roster sync moved
     * them to the ELOA group, so the org page and roster can say where they came from. Null for a trooper the portal
     * first saw on ELOA, and cleared when the sync places them anywhere else. Nulled when that unit is deleted.
     *
     * `primary_section_id` keeps its name: since the same date it holds the trooper's primary unit, a section, an HQ
     * node (a platoon, company, battalion or the regiment) or a group.
     */
    public function up(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->foreignId('last_line_unit_id')->nullable()->after('placement_source')->constrained('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_line_unit_id');
        });
    }
};
