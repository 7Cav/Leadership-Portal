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
        // Portal-owned (AD-22): a section's fireteams, which Cav does not publish. A fireteam leader (FTL) is an
        // unofficial role a section's leaders give a trooper so the rollcall post can list them above the rest, and a
        // member row puts a trooper in one FTL's fireteam. Not re-fetchable, so it belongs on the backup list. One
        // writer: the audited `SetFireteamRole` Command, through `FireteamRepository`; no `Sync/` handler touches it.
        //
        // One row per trooper in a section: `ftl` with no `leader_trooper_id`, or `member` with the FTL it follows. A
        // trooper with no row is in no fireteam. Rows are read only for troopers still placed in the section, so a
        // trooper the roster sync moves elsewhere drops out of the fireteams without a write here.
        //
        // The `role` list mirrors `App\Models\FireteamRole`, kept as literals so this migration stays a frozen record
        // of the schema it created.
        Schema::create('fireteam_assignments', function (Blueprint $table) {
            $table->id();
            // `cascadeOnDelete`: fireteams describe the section as it stands and carry no history, so they never
            // hold a unit (or a trooper, which is never hard-deleted anyway, AD-4) in place.
            $table->foreignId('section_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('trooper_id')->constrained('troopers')->cascadeOnDelete();
            $table->enum('role', ['ftl', 'member']);
            $table->foreignId('leader_trooper_id')->nullable()->constrained('troopers')->cascadeOnDelete();
            $table->timestamps();

            // A trooper holds one fireteam role per section. `section_id` leads, so this is also the per-section read.
            $table->unique(['section_id', 'trooper_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fireteam_assignments');
    }
};
