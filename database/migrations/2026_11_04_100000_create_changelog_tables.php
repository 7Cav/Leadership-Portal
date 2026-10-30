<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The changelog: entries the changelog editors write by hand, never pulled from git. Portal-owned (AD-22), written
     * only by the audited `app/Commands/Changelog` Commands through `ChangelogRepository`.
     *
     * - `changelog_entries`: one change each. A date (a Zulu day), the change text as one line (`body`) and optional
     *   additional information in Markdown (`details`). Entries are grouped by date on the page. The date is the one
     *   the editor gives, which may be earlier than the day the entry was written; the "new" dot compares `created_at`.
     * - `changelog_seen`: when each user last opened the changelog, for the sidebar's dot. A read marker, not an audited
     *   write, like the activity log's page visits.
     * - `changelog_state`: one row. `acknowledged_deploy_id` is the deploy (the asset build version) an editor last added
     *   an entry for or dismissed the "Changelog outdated" bar on.
     */
    public function up(): void
    {
        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('body', 300);
            $table->text('details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'id']);
        });

        Schema::create('changelog_seen', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->dateTime('seen_at');
        });

        Schema::create('changelog_state', function (Blueprint $table) {
            $table->id();
            $table->string('acknowledged_deploy_id', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_state');
        Schema::dropIfExists('changelog_seen');
        Schema::dropIfExists('changelog_entries');
    }
};
