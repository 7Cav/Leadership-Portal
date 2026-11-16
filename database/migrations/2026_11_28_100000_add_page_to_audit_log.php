<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The portal page a change was made on (its path and query, `App\Support\Audit\AuditPage`), so the audit log can
     * link back to it. Null for entries from before, and for changes with no page behind them (a sync, a queued job).
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->string('page', 500)->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn('page');
        });
    }
};
