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
        // Cache table (AD-4/AD-5/AD-22): the forum is the server of record for leave, so this is a
        // re-fetchable copy. Its one writer will be `Sync\SyncLoasHandler` (4.2), upserting keyed by
        // `forum_thread_id` onto this table's own PK so rows are never truncated and reinserted. No
        // display, read or Command path writes it.
        Schema::create('loas', function (Blueprint $table) {
            $table->id();
            // Null while the thread's `Username` matches no trooper exactly: the row is kept as
            // `needs_review` with its raw text rather than dropped, so an unmatched leave request
            // is seen by a person, never silently lost. `restrictOnDelete`: troopers are never
            // deleted (AD-4), so nothing should be able to orphan a leave record.
            $table->foreignId('trooper_id')->nullable()->constrained('troopers')->restrictOnDelete();
            // The authoritative source key and the re-sync key (AD-22): one forum thread is one LOA.
            $table->unsignedBigInteger('forum_thread_id')->unique();
            // The forum section (node) the thread was found in. LOA sections are per battalion, so it
            // also says where the trooper is posted: kept so a thread whose username matched nobody
            // can be sent to the right battalion to check.
            $table->unsignedInteger('forum_node_id');
            // Which kind of leave, taken from the section the thread is posted in (LOA sections are per
            // battalion, ELOA has its own); the post itself carries only a username and two dates.
            $table->enum('type', ['loa', 'eloa', 'reserve']);
            // Calendar days, inclusive at both ends, so plain dates rather than timestamps: a time
            // of day would only invite off-by-one bugs at the boundaries. Both are nullable: an open
            // ended ELOA/Reserve has no end, and a `needs_review` row asserts no dates at all.
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            // `needs_review` is the fail-safe: dates that could not be read cleanly are never
            // guessed, and a consumer treats the row as unknown, not as leave.
            $table->enum('parse_status', ['ok', 'needs_review']);
            // The thread's address, for display and for a person to follow up.
            $table->string('source_thread');
            // What the post actually said, kept so a row can be re-parsed or reviewed later.
            $table->string('raw_username')->nullable();
            $table->string('raw_start_date')->nullable();
            $table->string('raw_end_date')->nullable();
            $table->text('raw_excerpt')->nullable();
            $table->timestamps();

            // "Leave for this trooper, newest first": the read the badge and resolver make.
            $table->index(['trooper_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loas');
    }
};
