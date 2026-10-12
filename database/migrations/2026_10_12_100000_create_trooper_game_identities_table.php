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
        // Portal-owned (AD-5/AD-22): which in-game player a trooper *is* (AD-19). A link is
        // made by a leader, never inferred, so it is not re-fetchable from any source and this
        // table MUST be on the backup list. Exactly one writer — 7.2's audited
        // `SetTrooperGameIdentity` Command, through `TrooperGameIdentityRepository`; no `Sync/`
        // handler ever touches it.
        //
        // The `platform` list mirrors `App\Models\GamePlatform`, kept as literals so this migration
        // stays a frozen record of the schema it created.
        Schema::create('trooper_game_identities', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete`: a trooper is never hard-deleted (AD-4 flips them inactive).
            $table->foreignId('trooper_id')->constrained('troopers')->restrictOnDelete();
            $table->enum('platform', ['hll', 'arma']);
            $table->string('player_name');
            // The Steam/platform identifier the capture resolver matches an observed player on.
            $table->string('platform_id');
            $table->timestamps();

            // One in-game identity belongs to at most one trooper — one human is never counted
            // as two. Also the index the resolver reads by.
            $table->unique(['platform', 'platform_id']);
            // A trooper holds at most one identity per platform; a second replaces the first via
            // the Command. `trooper_id` leads, so this also covers the per-trooper read.
            $table->unique(['trooper_id', 'platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trooper_game_identities');
    }
};
