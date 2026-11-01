<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an entry is about, written with it by the audit log's one writer (AD-16): the subject as it read at the time
     * ("Staff report of 1LT Lindqvist.P, week 17OCT26 to 23OCT26") and the before and after values of what changed, so
     * an entry still names its subject after the target is deleted. Null on every row written before this column.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->json('details')->nullable()->after('content_digest');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
