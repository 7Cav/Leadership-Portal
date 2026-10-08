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
        // Two more synced display columns (2.6), refreshed on every roster sync like `rank`/`mos`.
        // `username` is the milpac name ("Hollis.D") a leader recognises a trooper by; it is display
        // text only and is never used to match or identify a trooper (the `cav_id` is the key).
        // `cav_billet` is the primary billet's title as Cav reports it: free text a reorg can rename,
        // so it only tells a person which billet failed to resolve; placement stays keyed on
        // `cav_position_id`. Both are nullable because Cav may report neither (a trooper with no
        // forum account has no username), and both stay null until the next roster sync fills them.
        Schema::table('troopers', function (Blueprint $table) {
            $table->string('username')->nullable()->after('cav_id');
            $table->string('cav_billet')->nullable()->after('cav_position_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropColumn(['username', 'cav_billet']);
        });
    }
};
