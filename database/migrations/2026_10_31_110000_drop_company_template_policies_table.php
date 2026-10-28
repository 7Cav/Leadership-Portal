<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Battalion command no longer switches off a company's own template editing (#376 is withdrawn): a company's command
     * always manages its own templates, and each section may keep its own. The switch's table goes with it. Audit log
     * entries about a policy (`template_policy.set`, target `CompanyTemplatePolicy`) are kept: the log reads a target
     * type it no longer knows as one with nothing to link to.
     */
    public function up(): void
    {
        Schema::dropIfExists('company_template_policies');
    }

    /** The table as it stood before, with the acting columns of 2026_10_30_110000. The rows themselves are gone. */
    public function down(): void
    {
        Schema::create('company_template_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('units')->cascadeOnDelete();
            $table->boolean('company_edits_templates')->default(true);
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('set_by_admin')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('set_as_unit')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamps();
        });
    }
};
