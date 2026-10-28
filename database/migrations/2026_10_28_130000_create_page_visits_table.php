<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pages each signed-in person opened and when (#382), so an Admin can retrace the steps behind a bug report.
     * Portal-owned (AD-22) and short-lived: the `RecordPageVisit` middleware is the one writer, after the response, and
     * `portal:prune-page-visits` deletes rows older than 30 days. Not the audit log: a visit changes nothing, so it is
     * never an `audit_log` row (AD-16).
     *
     * Only the address and the time are kept, never a form or a request body. `acting_as` and `viewing_as` hold the
     * name the shell showed for an act as (#379) or view as (AD-9) in effect on the visit, as text: the log keeps
     * reading right after the person or unit behind it is gone, and the rows are deleted within a month anyway.
     *
     * `user_id` cascades: the log of a removed account has no use, and it must never stop the account being removed.
     */
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('url', 1024);
            $table->string('acting_as')->nullable();
            $table->string('viewing_as')->nullable();
            $table->timestamp('visited_at');

            // The two reads: the Activity log page (newest first, optionally one person's) and the daily prune.
            $table->index('visited_at');
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
