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
        // A battalion's own settings (#558). `presence_minimum_minutes`: how long a trooper must be on the practice's
        // server inside its hour to count as attending; null uses the default from config.
        Schema::create('battalion_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->unique()->constrained('units')->cascadeOnDelete();
            $table->unsignedSmallInteger('presence_minimum_minutes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battalion_settings');
    }
};
