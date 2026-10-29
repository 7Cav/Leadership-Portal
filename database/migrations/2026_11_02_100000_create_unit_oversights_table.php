<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22): the regimental staff officer who oversees a unit (decided by the S6, 2026-10-29), such as an
     * Adjutant General over a battalion or the RDC Commander over ACD, DEVCOM and the Reservists. Cav's API does not
     * carry it. One overseer per unit, by trooper, so it holds before they first sign in. Read by ScopeResolver as read
     * scope over the unit's subtree while the overseer serves, never as write. Written only by the audited
     * `SetUnitOversightHandler` (AD-16). No row is seeded: an Admin enters each one.
     *
     * The who-columns follow every other record's (#379): `set_by` names who set it, `set_by_admin` the Admin acting as
     * them, `set_as_unit` the unit whose billet an Admin held. People `restrictOnDelete`, the unit `nullOnDelete`.
     */
    public function up(): void
    {
        Schema::create('unit_oversights', function (Blueprint $table) {
            $table->id();
            // The oversight goes with the unit, as an acting lead does.
            $table->foreignId('unit_id')->unique()->constrained('units')->cascadeOnDelete();
            $table->foreignId('trooper_id')->constrained('troopers')->cascadeOnDelete();
            $table->foreignId('set_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('set_by_admin')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('set_as_unit')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_oversights');
    }
};
