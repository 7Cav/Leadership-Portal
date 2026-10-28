<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #450: whether an identity's player name mixes alphabets (Latin with Cyrillic, say), so a leader can check it for
     * a look-alike. Set by the model on every save of a name. Portal-owned (AD-22). Rows saved before stay null, never
     * rewritten here: the screens work the flag out for those when they show them.
     */
    public function up(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->boolean('name_mixes_scripts')->nullable()->after('player_name');
        });
    }

    public function down(): void
    {
        Schema::table('trooper_game_identities', function (Blueprint $table) {
            $table->dropColumn('name_mixes_scripts');
        });
    }
};
