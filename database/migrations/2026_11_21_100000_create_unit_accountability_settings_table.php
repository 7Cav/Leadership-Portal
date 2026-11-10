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
        // What counts for accountability on a unit: its HQ staff report's accountability (battalion, company, platoon)
        // or a section's makeups. `options` is a JSON list of option keys; no row means the unit follows its nearest
        // ancestor with one, else the default.
        Schema::create('unit_accountability_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->unique()->constrained('units')->cascadeOnDelete();
            $table->json('options');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_accountability_settings');
    }
};
