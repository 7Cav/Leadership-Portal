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
        Schema::table('loas', function (Blueprint $table) {
            // The "[ELOA Return]" thread that ended this ELOA (4.2). A return is not a leave period, it
            // ends the trooper's open ELOA, so it has no row of its own: it sets this row's `ends_at` and
            // is remembered here. Remembering it is what keeps a later re-read of the ELOA thread, which
            // still says "no end", from reopening the leave. UNIQUE: one return ends one ELOA.
            $table->unsignedBigInteger('return_thread_id')->nullable()->unique()->after('forum_thread_id');
            // Why a `needs_review` row needs a person, in plain words, so whoever looks does not have to
            // work it out from the raw text. Null for an `ok` row.
            $table->string('review_reason')->nullable()->after('parse_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loas', function (Blueprint $table) {
            $table->dropColumn(['return_thread_id', 'review_reason']);
        });
    }
};
