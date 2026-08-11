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
        // Portal-owned, backed up (AD-22, AD-7): explicit, audited scope for non-admin staff
        // whose access the roster can't imply (e.g. S-shop staff with no line billet). A grant
        // always attaches a user to a unit — there is no scopeless grant (AD-8, AD-18). The
        // creating/revoking Command (audited per AD-16) is Story 1.12; this table is the one
        // writer's target, not yet the write path itself.
        Schema::create('portal_role_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->timestamps();

            // A user can hold the same node's grant only once; re-granting an already-held node
            // is a no-op, not a second row that later has to be de-duplicated.
            $table->unique(['user_id', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_role_grants');
    }
};
