<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trooper's own appeal of an LOR, or their comment or dispute on a DCS, filed within the 72 hours after the record
     * was filed. Portal-owned (AD-22), written once by the audited `AppealCounselingRecord` Command: one per record.
     *
     *  - `appealed_by` restricts, like `issued_by`: the record keeps naming who appealed.
     *  - `appeal_statement` is what the trooper wrote (at most 2000 characters).
     */
    public function up(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->timestamp('appealed_at')->nullable()->after('overturn_reason');
            $table->foreignId('appealed_by')->nullable()->after('appealed_at')->constrained('users')->restrictOnDelete();
            $table->text('appeal_statement')->nullable()->after('appealed_by');
        });
    }

    public function down(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appealed_by');
            $table->dropColumn(['appealed_at', 'appeal_statement']);
        });
    }
};
