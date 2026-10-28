<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). #378: a platoon (or section) moved to another company keeps the templates its sections
     * used: each is carried along with it, and its sections may go on using it in the new company. One row per moved
     * unit and template. The writer is `MoveUnit`, through the audited `CarryUnitTemplates` and
     * `TemplateVersionRepository`.
     */
    public function up(): void
    {
        Schema::create('template_carries', function (Blueprint $table) {
            $table->id();
            // `cascadeOnDelete`: a carry is a setting of the moved unit and never holds it in the tree.
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            // `restrictOnDelete`: templates are never deleted.
            $table->foreignId('template_id')->constrained('templates')->restrictOnDelete();
            // The company the template came from. `nullOnDelete`: the record of where it came from may outlive it.
            $table->foreignId('from_company_id')->nullable()->constrained('units')->nullOnDelete();
            // `restrictOnDelete`, as for every other "who did it".
            $table->foreignId('carried_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['unit_id', 'template_id']);
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_carries');
    }
};
