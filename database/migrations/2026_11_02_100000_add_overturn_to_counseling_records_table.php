<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An LOR the Regimental Executive Officer overturned after its 72-hour appeal window (S6, 2026-10-29). Portal-owned
     * (AD-22), written once by the audited `OverturnCounselingRecord` Command. The record is never deleted: it stays on
     * file marked overturned and stops counting toward the counseling rules.
     *
     *  - `overturned_by` and `overturned_by_admin` restrict, like `issued_by`: the record keeps naming who overturned it.
     *    `overturned_by_admin` is the one acting as `overturned_by` (#379, #432), null otherwise.
     *  - `overturned_as_unit` (the billet held while acting as one) nulls out with its unit, as the other acting-billet
     *    columns do.
     */
    public function up(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->timestamp('overturned_at')->nullable()->after('drive_attempted_at');
            $table->foreignId('overturned_by')->nullable()->after('overturned_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('overturned_by_admin')->nullable()->after('overturned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('overturned_as_unit')->nullable()->after('overturned_by_admin')->constrained('units')->nullOnDelete();
            $table->text('overturn_reason')->nullable()->after('overturned_as_unit');
        });
    }

    public function down(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overturned_as_unit');
            $table->dropConstrainedForeignId('overturned_by_admin');
            $table->dropConstrainedForeignId('overturned_by');
            $table->dropColumn(['overturned_at', 'overturn_reason']);
        });
    }
};
