<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the roster sync saw a trooper leave every serving roster (the 2026-10-29 audit): set as their status flips to
     * inactive and cleared when they come back. A past week's rollcall keeps a trooper who left during or after it, so
     * it needs the date. Null for a trooper who left before this column existed: when they left is not known, so they
     * stay off every past rollcall, as before.
     */
    public function up(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->timestamp('departed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('troopers', function (Blueprint $table) {
            $table->dropColumn('departed_at');
        });
    }
};
