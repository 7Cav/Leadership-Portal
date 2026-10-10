<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Portal-owned (AD-22): an Admin's template edits are not re-fetchable from anywhere, so this table must
        // be backed up. A version's `body` is written once and never changed (AD-17): an edit is a new row, so
        // "what format did we post then?" always has an answer. The single writer of record is the audited
        // `AuthorTemplateVersion` Command, through `TemplateVersionRepository`.
        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            // The owning company node (AD-17): every section and platoon below it renders from this company's
            // active version. `restrictOnDelete` so a company holding history cannot be deleted from under it.
            $table->foreignId('company_id')->constrained('units')->restrictOnDelete();
            // Which kind of artifact the body templates, so a future report layout reuses this table. Mirrors
            // `App\Models\ArtifactType` by hand, kept as a string so a new type needs no migration.
            $table->string('artifact_type', 40);
            // 1, 2, 3 ... per (company, artifact type). Assigned by the repository inside its transaction.
            $table->unsignedInteger('version');
            $table->text('body');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Created once and never updated, so there is no `updated_at`.
            $table->timestamp('created_at')->useCurrent();

            // Two racing authors can never both take version N: the second insert fails on this index and retries.
            $table->unique(['company_id', 'artifact_type', 'version']);
        });

        // The active-version pointer (AD-17). A row per (company, artifact type), and the primary key is what makes
        // "exactly one active version" true at the data layer, so a second activation moves this row instead of
        // adding one. It is kept apart from `template_versions` so activating never touches a version row.
        Schema::create('active_template_versions', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('units')->restrictOnDelete();
            $table->string('artifact_type', 40);
            $table->foreignId('template_version_id')->constrained('template_versions')->restrictOnDelete();
            $table->foreignId('activated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['company_id', 'artifact_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_template_versions');
        Schema::dropIfExists('template_versions');
    }
};
