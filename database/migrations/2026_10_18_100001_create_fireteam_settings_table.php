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
        // Portal-owned (AD-22): how a unit's rollcall post shows fireteams. Kept off `units`, whose chain-of-command
        // columns only the Admin's org-structure Commands write. One writer: the audited `SetSectionFireteamOptions`
        // and `SetCompanyFireteamPolicy` Commands, through `FireteamRepository`. At most one row per unit; a unit with
        // no row has every value null.
        //
        // The same two columns mean different things by echelon. On a section they are its leaders' choice, null for
        // the default (FTLs not shown; when shown, all FTLs above the rest). On a company they are a policy over every
        // section beneath it, null to leave the choice to the sections. A section's effective value is the company's
        // policy, else its own choice, else the default (`FireteamSetting::effective()`).
        //
        // The `fireteam_order` list mirrors `App\Models\FireteamOrder`, kept as literals so this migration stays a
        // frozen record of the schema it created.
        Schema::create('fireteam_settings', function (Blueprint $table) {
            $table->id();
            // `cascadeOnDelete`: a display preference never holds a unit in the tree.
            $table->foreignId('unit_id')->unique()->constrained('units')->cascadeOnDelete();
            // `leaders_first`: every FTL above the rest. `by_fireteam`: each FTL directly above their members.
            $table->enum('fireteam_order', ['leaders_first', 'by_fireteam'])->nullable();
            // Whether the post names FTLs and lists them above the troopers they lead.
            $table->boolean('show_fireteams')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fireteam_settings');
    }
};
