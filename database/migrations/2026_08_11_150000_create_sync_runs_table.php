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
        // Portal-owned (AD-22), source-agnostic (Story 2.3): every cache sync — roster today,
        // Epic 4's LOA sync later — opens and closes a row here instead of routing through the
        // per-Command audited-action envelope (AD-16 carve-out for system jobs).
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status');
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('inactivated')->default(0);
            $table->unsignedInteger('errored')->default(0);
            $table->text('error')->nullable();
            // A user id (manual sync, 2.4) or the literal 'system' (scheduler) — no FK, since
            // 'system' isn't a row in `users` and this table must never block on one existing.
            $table->string('triggered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
