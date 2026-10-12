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
        // The trooper's forum identity (2.10), synced from the roster payload's `user` object and
        // overwritten on every run so a forum rename follows. 7Cav names a member `RANK Lastname.I`,
        // so the short rank (`SGT`) is cached beside the full one `rank` already holds. All
        // nullable: a roster profile need not have a forum account linked (2.1). `forum_user_id`
        // is indexed so a portal user can be matched to their trooper by forum id as well as by
        // `cav_id`. The real name is deliberately not stored (data minimisation).
        Schema::table('troopers', function (Blueprint $table) {
            $table->string('rank_short')->nullable()->after('rank');
            $table->string('username')->nullable()->after('cav_id');
            $table->unsignedBigInteger('forum_user_id')->nullable()->index()->after('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropIndex(['forum_user_id']);
            $table->dropColumn(['rank_short', 'username', 'forum_user_id']);
        });
    }
};
