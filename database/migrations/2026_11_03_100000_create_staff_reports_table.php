<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A platoon or company HQ staff member's own weekly staff report: whether they attended at
     * least one section practice that Cav week, and which. One row per trooper and week, written once when the staff
     * member sends it and never changed after (a sent report is final, as a unit's weekly report is). There is no
     * draft: the page keeps the picks until it is sent.
     *
     * The practices picked are kept as `cross_section_sightings` rows with source `staff`, the same store the milpac's
     * staff practice entries use, so the host section's rollcall lists them. `practices` freezes what was sent
     * (`[{section_id, section}]`) so the record reads back as sent even if a section is renamed or deleted later.
     * `unit_id` is the HQ the trooper was placed on when the week ended, the one whose staff they reported as.
     */
    public function up(): void
    {
        Schema::create('staff_reports', function (Blueprint $table) {
            $table->id();
            // A trooper row is never deleted while something still points at it.
            $table->foreignId('trooper_id')->constrained('troopers')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->dateTime('week_start');
            $table->boolean('attended');
            $table->json('practices');
            $table->dateTime('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['trooper_id', 'week_start'], 'staff_reports_unique');
            $table->index(['unit_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_reports');
    }
};
