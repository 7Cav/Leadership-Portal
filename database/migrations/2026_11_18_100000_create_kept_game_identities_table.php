<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Game identities kept on file for a Cav name that has no trooper in the portal (someone who left before the portal
     * began syncing), so they come back if that person rejoins. `cav_name` is as written, `cav_name_key` its lower case
     * form, matched exactly (never by prefix). `platform_id` is stored as a save would store it. A roster sync moves a
     * row onto the trooper of that exact name once one appears; `conflict` says why it could not. Never matched by a
     * capture. Portal-owned (AD-22).
     */
    public function up(): void
    {
        Schema::create('kept_game_identities', function (Blueprint $table) {
            $table->id();
            $table->string('cav_name', 100);
            $table->string('cav_name_key', 100);
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->string('id_kind', 50);
            $table->string('platform_id', 100);
            $table->string('gamertag', 100)->nullable();
            $table->boolean('gamertag_from_server')->default(false);
            $table->string('source', 20)->default('imported');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('conflict', 255)->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'platform_id']);
            $table->unique(['cav_name_key', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kept_game_identities');
    }
};
