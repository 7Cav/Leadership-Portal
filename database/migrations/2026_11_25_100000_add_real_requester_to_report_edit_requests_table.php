<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who really asked when the request was made while acting as someone (#379 act as): `requested_by` is the
     * person acted as, `real_requested_by` the signed-in user behind them. Neither may decide the request, so nobody
     * approves their own by switching who they act as. Null when nobody was acting.
     */
    public function up(): void
    {
        Schema::table('report_edit_requests', function (Blueprint $table) {
            $table->foreignId('real_requested_by')->nullable()->after('requested_by')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_edit_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('real_requested_by');
        });
    }
};
