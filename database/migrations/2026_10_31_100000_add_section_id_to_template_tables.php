<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). A section can keep named templates of its own, exactly as a company does: the same tables,
     * the same versions, the same audited Commands. A template now belongs to a company (`company_id`), to a section
     * (`section_id`), or to neither, which is the regiment default. Never both: the repository writes one or the other.
     *
     * A section's templates are its own, keyed to the section and not to its company, so they move with it when it
     * moves to another platoon or company. `template_versions.section_id` repeats the owner as `company_id` does, so the
     * audit log's unit filter finds a section's versions in one indexed read.
     *
     * `restrictOnDelete`, as for `company_id`: a section holding templates cannot be deleted from under them (AD-17 keeps
     * every version). Both tables are rebuilt on SQLite to add the key; neither has an enum column, so no CHECK is lost.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('company_id')->constrained('units')->restrictOnDelete();
            $table->index(['section_id', 'artifact_type']);
        });

        Schema::table('template_versions', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('company_id')->constrained('units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Without the column a section's template would read as the regiment default. Rolling back would have to throw
        // those templates away, so it refuses, as the templates migration does for a company with several.
        if (DB::table('templates')->whereNotNull('section_id')->exists()) {
            throw new RuntimeException('A section holds templates of its own; the old schema cannot hold them.');
        }

        Schema::table('template_versions', fn (Blueprint $table) => $table->dropConstrainedForeignId('section_id'));

        // MySQL lets the foreign key use the `(section_id, artifact_type)` index, so the key goes first, then the index.
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropIndex(['section_id', 'artifact_type']);
            $table->dropColumn('section_id');
        });
    }
};
