<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). #376: battalion command can switch off, per company under it, the company's right to set its
     * own rollcall templates. One row per company that has been switched either way; a company with no row may set
     * them (the default). The one writer is the audited `SetCompanyTemplatePolicy` Command, through
     * `TemplateVersionRepository`.
     */
    public function up(): void
    {
        Schema::create('company_template_policies', function (Blueprint $table) {
            $table->id();
            // `cascadeOnDelete`: a policy is a setting and never holds a company in the tree.
            $table->foreignId('company_id')->unique()->constrained('units')->cascadeOnDelete();
            // Whether the company's own command may create, edit, activate and switch its templates.
            $table->boolean('company_edits_templates')->default(true);
            // `restrictOnDelete`, as for every other "who set it": users are never deleted while they hold history.
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_template_policies');
    }
};
