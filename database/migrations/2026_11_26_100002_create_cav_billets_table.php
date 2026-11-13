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
        // Portal-owned (AD-22): every billet the Cav lists, by its stable position id, with the name it carries now. The
        // org import records every billet with its group and the rule that places it; the roster sync records the
        // billets serving troopers hold. `confirmed_title` is the name the billet's placement was confirmed under: when
        // the Cav renames a placed billet the two differ until an Admin confirms the mapping follows the new name. A
        // billet no rule or mapping places is new until an Admin assigns it to a unit or leaves it unplaced.
        Schema::create('cav_billets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cav_position_id')->unique();
            $table->string('title');
            // The Cav group it is listed in. Null until the import has seen it: the roster carries no group.
            $table->string('group_title')->nullable();
            $table->string('confirmed_title')->nullable();
            // Why the import places it, such as "line billet in A/1-7". Null for a billet no rule places.
            $table->string('rule')->nullable();
            // An Admin chose to leave this billet unplaced, so it is not new any more until its name changes.
            $table->timestamp('unplaced_at')->nullable();
            $table->timestamps();
        });

        // An Admin acknowledged that a billet sits in two units at once on purpose, such as the one Cav title of the
        // two Regimental Deputy Chiefs of Staff placed in both their groups. `conflict_key` names the conflict (its
        // kind and the billet name or position id); `placements` is the exact set of billets and units acknowledged,
        // so any change to where they sit brings the warning back.
        Schema::create('cav_billet_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->string('conflict_key', 300)->unique();
            $table->string('title');
            $table->text('placements');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cav_billet_acknowledgements');
        Schema::dropIfExists('cav_billets');
    }
};
