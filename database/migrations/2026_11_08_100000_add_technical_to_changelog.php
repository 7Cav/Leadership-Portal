<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Technical changelog items: behind-the-scenes and security work (CI, tests, dependency updates, refactors,
     * infrastructure, security hardening).
     *
     * - `changelog_entries.technical` marks such an entry. Never together with `major`. Existing entries stay regular.
     * - `changelog_state.technical_enabled` is the Admin setting that makes them available to everyone. Off by default:
     *   while off, only the changelog editors are sent technical entries.
     */
    public function up(): void
    {
        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->boolean('technical')->default(false)->after('major');
        });

        Schema::table('changelog_state', function (Blueprint $table) {
            $table->boolean('technical_enabled')->default(false)->after('acknowledged_deploy_id');
        });
    }

    public function down(): void
    {
        Schema::table('changelog_state', function (Blueprint $table) {
            $table->dropColumn('technical_enabled');
        });

        Schema::table('changelog_entries', function (Blueprint $table) {
            $table->dropColumn('technical');
        });
    }
};
