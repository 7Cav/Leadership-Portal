<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a leader confirmed the practice's marks as reviewed, and who (7.8, AD-16). Distinct
        // from `rollcall_posted_*` (the forum post, 3.10). Cleared when a practice mark is later
        // changed by hand, so "confirmed" always describes the marks as they now stand.
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dateTime('confirmed_at')->nullable()->after('rollcall_posted_by');
            // `nullOnDelete`: removing a user must not take the section's practice history with it.
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('section_practices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });
    }
};
