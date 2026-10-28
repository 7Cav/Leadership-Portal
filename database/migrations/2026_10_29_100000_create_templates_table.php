<?php

use App\Rollcall\TemplateSyntax;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22), and not re-fetchable from anywhere, so these tables must be backed up. Their one writer is
     * the audited template Commands (AD-16) through `TemplateVersionRepository`.
     *
     * #375: a company keeps several named templates instead of one, its command (not only an Admin) manages them, and a
     * regiment-wide default always exists. So a version now belongs to a template, not straight to a company:
     *
     *  - `templates`: one row per named template. `company_id` null is the regiment-wide default. `enabled` is the
     *    company command's switch for whether its sections may use it. `active_version_id` is the version pointer that
     *    used to be `active_template_versions`, one per template now.
     *  - `template_versions.template_id`: the template a version belongs to. `company_id` stays as the owner (null for
     *    the default) so the audit log's company filter keeps reading it; the repository writes both together.
     *  - `unit_template_choices`: a unit's choice of template. On a company it is the company's active template, on a
     *    section the leader's pick. One row per unit and artifact type.
     *
     * Existing data carries over: each company's version history becomes one template, switched on, that is the
     * company's active one, so every section renders exactly what it did. The regiment default is seeded from
     * `TemplateSyntax::STARTER` when none exists; it has no author (`created_by` null), since no user wrote it.
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete`, as for a version: a company holding templates cannot be deleted from under them.
            $table->foreignId('company_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('artifact_type', 40);
            $table->string('name', 60);
            // Whether the company's sections may use it. The regiment default is always available and ignores it.
            $table->boolean('enabled')->default(true);
            // `restrictOnDelete`: users are never deleted while they hold history. Null only for the seeded default.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'artifact_type']);
        });

        Schema::table('template_versions', function (Blueprint $table) {
            // `restrictOnDelete`: a template is never deleted while it has versions (none ever is).
            $table->foreignId('template_id')->nullable()->after('id')->constrained('templates')->restrictOnDelete();
        });

        // The old unique index starts with `company_id`, so MySQL uses it for that column's foreign key and will not drop
        // it until another index covers the column.
        Schema::table('template_versions', function (Blueprint $table) {
            $table->index('company_id');
        });

        Schema::table('template_versions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'artifact_type', 'version']);
            $table->unique(['template_id', 'version']);
            $table->foreignId('company_id')->nullable()->change();
            $table->foreignId('created_by')->nullable()->change();
        });

        Schema::table('templates', function (Blueprint $table) {
            // `restrictOnDelete`, as `active_template_versions` had: a version is never deleted while it is the active one.
            $table->foreignId('active_version_id')->nullable()->after('enabled')->constrained('template_versions')->restrictOnDelete();
        });

        Schema::create('unit_template_choices', function (Blueprint $table) {
            $table->id();
            // `cascadeOnDelete`: a choice is a preference and never holds a unit in the tree.
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('artifact_type', 40);
            // `restrictOnDelete`: templates are never deleted.
            $table->foreignId('template_id')->constrained('templates')->restrictOnDelete();
            $table->foreignId('chosen_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['unit_id', 'artifact_type']);
            $table->index('template_id');
        });

        $this->carryOver();
        $this->seedDefault();

        // Every version has its template now, so the column can say it must.
        Schema::table('template_versions', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable(false)->change();
        });

        Schema::dropIfExists('active_template_versions');
    }

    public function down(): void
    {
        $companies = DB::table('templates')->whereNotNull('company_id')->select('company_id', 'artifact_type')
            ->groupBy('company_id', 'artifact_type')->havingRaw('count(*) > 1')->exists();

        // The old schema holds one template per company. Rolling back would have to throw versions away, so it refuses.
        if ($companies) {
            throw new RuntimeException('A company holds more than one template; the old schema cannot hold them.');
        }

        Schema::create('active_template_versions', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('units')->restrictOnDelete();
            $table->string('artifact_type', 40);
            $table->foreignId('template_version_id')->constrained('template_versions')->restrictOnDelete();
            $table->foreignId('activated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['company_id', 'artifact_type']);
        });

        foreach (DB::table('templates')->whereNotNull('company_id')->whereNotNull('active_version_id')->get() as $template) {
            DB::table('active_template_versions')->insert([
                'company_id' => $template->company_id,
                'artifact_type' => $template->artifact_type,
                'template_version_id' => $template->active_version_id,
                'activated_by' => DB::table('template_versions')->where('id', $template->active_version_id)->value('created_by'),
                'created_at' => $template->updated_at,
                'updated_at' => $template->updated_at,
            ]);
        }

        Schema::dropIfExists('unit_template_choices');

        Schema::table('templates', fn (Blueprint $table) => $table->dropConstrainedForeignId('active_version_id'));

        // The regiment default has no company and no author, so the old columns cannot hold it.
        $defaults = DB::table('template_versions')->whereNull('company_id')->pluck('id');
        DB::table('template_versions')->whereIn('id', $defaults)->delete();

        // The foreign key first: on MySQL the (template, version) index is the one it uses.
        Schema::table('template_versions', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
        });

        Schema::table('template_versions', function (Blueprint $table) {
            $table->dropUnique(['template_id', 'version']);
            $table->dropColumn('template_id');
            $table->unique(['company_id', 'artifact_type', 'version']);
            $table->foreignId('company_id')->nullable(false)->change();
            $table->foreignId('created_by')->nullable(false)->change();
        });

        Schema::table('template_versions', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
        });

        // Emptied first: SQLite deletes a table's rows on drop, and checks each against keys as it does.
        DB::table('templates')->delete();
        Schema::dropIfExists('templates');
    }

    /** Each company's existing versions become one template, switched on and the company's active one. */
    private function carryOver(): void
    {
        $owners = DB::table('template_versions')->select('company_id', 'artifact_type')->distinct()->get();

        foreach ($owners as $owner) {
            $versions = DB::table('template_versions')->where('company_id', $owner->company_id)->where('artifact_type', $owner->artifact_type);
            $first = (clone $versions)->orderBy('version')->first();
            $pointer = DB::table('active_template_versions')->where('company_id', $owner->company_id)->where('artifact_type', $owner->artifact_type)->first();

            $templateId = DB::table('templates')->insertGetId([
                'company_id' => $owner->company_id,
                'artifact_type' => $owner->artifact_type,
                'name' => 'Company template',
                'enabled' => true,
                'created_by' => $first->created_by,
                'created_at' => $first->created_at,
                'updated_at' => $pointer->updated_at ?? $first->created_at,
            ]);

            (clone $versions)->update(['template_id' => $templateId]);

            if ($pointer !== null) {
                DB::table('templates')->where('id', $templateId)->update(['active_version_id' => $pointer->template_version_id]);
                DB::table('unit_template_choices')->insert([
                    'unit_id' => $owner->company_id,
                    'artifact_type' => $owner->artifact_type,
                    'template_id' => $templateId,
                    'chosen_by' => $pointer->activated_by,
                    'created_at' => $pointer->updated_at,
                    'updated_at' => $pointer->updated_at,
                ]);
            }
        }
    }

    /** The regiment-wide default, from the starter, when there is none yet. */
    private function seedDefault(): void
    {
        if (DB::table('templates')->whereNull('company_id')->where('artifact_type', 'rollcall_bbcode')->exists()) {
            return;
        }

        $now = now();
        $templateId = DB::table('templates')->insertGetId([
            'company_id' => null,
            'artifact_type' => 'rollcall_bbcode',
            'name' => 'Regiment default',
            'enabled' => true,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('template_versions')->insertGetId([
            'template_id' => $templateId,
            'company_id' => null,
            'artifact_type' => 'rollcall_bbcode',
            'version' => 1,
            'body' => TemplateSyntax::STARTER,
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('templates')->where('id', $templateId)->update(['active_version_id' => $versionId]);
    }
};
