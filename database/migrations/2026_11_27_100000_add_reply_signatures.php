<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signatures read from the roll call thread's replies.
     *
     * `attendance.rollcall_source` says who set the signature: `manual` a leader on the roll call, `reply` the reply
     * reader. Null on a row nobody signed. The values mirror `App\Models\RollcallSource` by hand. Every signature
     * recorded before this was set by hand, so each signed row is marked `manual`, and the reader never overwrites it.
     *
     * `rollcall_replies` keeps each trooper's latest reply on a week's thread: the post, when it was made, and the
     * answer read from it (null when none was recognised). It holds no post text. One row per trooper per practice, and
     * it goes with the practice or the trooper.
     */
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->enum('rollcall_source', ['manual', 'reply'])->nullable()->after('rollcall');
        });

        DB::table('attendance')->where('rollcall', '!=', 'unsigned')->update(['rollcall_source' => 'manual']);

        Schema::create('rollcall_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_practice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trooper_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('forum_post_id');
            $table->string('post_url', 500)->nullable();
            // Mirrors the signed values of `App\Models\RollcallSignature`; null is a reply with no answer recognised.
            $table->enum('answer', ['will_attend', 'cannot_attend', 'makeup_requested'])->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->unique(['section_practice_id', 'trooper_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollcall_replies');

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('rollcall_source');
        });
    }
};
