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
        // Portal-owned (AD-22): the chain of command is Admin-authored, not synced — no
        // Integration/Sync writer exists for it (AD-18). `parent_id` is nullable only for the
        // regiment root; every other node holds one. `path` is a cached materialized path
        // (e.g. "1/4/9/"), recomputed transactionally by `UnitRepository` on every write, so a
        // subtree query is a cheap `path LIKE 'ancestorpath%'` instead of a recursive walk.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->enum('type', ['regiment', 'battalion', 'company', 'platoon', 'section']);
            $table->string('name');
            $table->string('callsign')->nullable();
            $table->string('path')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
