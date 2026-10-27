<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acting leads (decided by the product owner 2026-10-25): a serving trooper an Admin assigns to lead a unit whose
     * lead the Cav roster does not name, such as a DEVCOM starter platoon run by a `DEVCOM Lead`. One per unit at most;
     * a trooper may lead several. Portal-owned and audited; read by ScopeResolver as a command over that unit.
     *
     * `units.lead_position_id` is the billet whose holder leads a group the import made (a regimental staff
     * portfolio), for the org page's lead or "vacant". It confers no scope; a command mapping does that.
     */
    public function up(): void
    {
        Schema::create('unit_acting_leads', function (Blueprint $table) {
            $table->id();
            // The lead goes with the unit, as a command mapping does.
            $table->foreignId('unit_id')->unique()->constrained('units')->cascadeOnDelete();
            $table->foreignId('trooper_id')->constrained('troopers')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_position_id')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('lead_position_id');
        });

        Schema::dropIfExists('unit_acting_leads');
    }
};
