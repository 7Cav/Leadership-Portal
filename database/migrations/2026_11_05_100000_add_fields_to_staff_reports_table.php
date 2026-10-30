<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rest of what the regiment's Google Forms asked: what kind of practice it was (`practice_type`, `sp` or `ftx`,
     * null when they answered No), the FTX's name and date when it was one, the other events they attended and their
     * notes. All nullable: a report sent before these fields read back as an SP with nothing else to say. Frozen as
     * sent, like the rest of the row.
     *
     * `sheet_pushed_at` is when the row went to the reports Google Sheet, null while it has not (not configured, or
     * still queued). It is the push's bookkeeping, the one column written after the send.
     */
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('practice_type', 8)->nullable()->after('attended');
            $table->string('ftx_name', 120)->nullable()->after('practice_type');
            $table->date('ftx_date')->nullable()->after('ftx_name');
            $table->text('other_events')->nullable()->after('practices');
            $table->text('notes')->nullable()->after('other_events');
            $table->dateTime('sheet_pushed_at')->nullable()->after('sent_by');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropColumn(['practice_type', 'ftx_name', 'ftx_date', 'other_events', 'notes', 'sheet_pushed_at']);
        });
    }
};
