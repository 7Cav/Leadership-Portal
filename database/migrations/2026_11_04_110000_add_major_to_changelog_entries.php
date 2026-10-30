<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `major` marks a changelog entry that also shows under "Major changes". Every entry shows in the full changelog;
     * existing entries stay full-only.
     */
    public function up(): void
    {
        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->boolean('major')->default(false)->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->dropColumn('major');
        });
    }
};
