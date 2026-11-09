<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An entry the portal writes itself, with nobody behind it (an overseer removed by the roster sync once they leave
     * active duty), has no actor. Every existing row keeps its actor; the audit log shows a null one as System.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable(false)->change();
        });
    }
};
