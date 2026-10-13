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
        // The primary billet's title (`Section Leader`, `Combat Medic`), what the unplaced-trooper
        // panel on Settings shows to explain why someone has no section (2.6). A synced display value, overwritten on every roster sync
        // like `rank`/`mos`; nullable because Cav may report a blank field (2.1's per-field
        // degrade). `cav_position_id` stays the key anything is mapped on — titles are free text.
        Schema::table('troopers', function (Blueprint $table) {
            $table->string('billet')->nullable()->after('mos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropColumn('billet');
        });
    }
};
