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
        // Portal-owned, backed-up, append-only (AD-22, AD-5): the one audit trail every
        // state-changing Command writes through (AD-16). No `updated_at` — an audit row is
        // never revised after it's written, only ever appended. `actor_id` targets `users`
        // (not `troopers`, which doesn't exist until Epic 2 — the same gap 1.9's `DeleteUnit`
        // already documents).
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('target_type');
            $table->string('target_id');
            $table->text('reason')->nullable();
            $table->string('content_digest');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
