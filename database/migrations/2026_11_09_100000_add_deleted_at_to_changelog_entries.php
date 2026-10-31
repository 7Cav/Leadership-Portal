<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delete all with a restore window: `changelog_entries.deleted_at` is set on every entry a delete all removes. Such an
     * entry is hidden everywhere, can be restored for 72 hours, and is then removed for good by `portal:purge-changelog`.
     * The rows stay where they were, so a restore brings back the same ids, text, dates and flags, in the same order.
     */
    public function up(): void
    {
        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
