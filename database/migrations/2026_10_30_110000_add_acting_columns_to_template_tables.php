<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22). The template records name who did something the way every other record does (#379, as
     * 2026_10_28_090100 and 2026_10_28_120000 do for reports, guests and topics): when an Admin acting as someone
     * creates or edits a template, sets a company's active one, picks a section's or sets a company's template policy,
     * the existing column keeps naming the person and `*_by_admin` names the Admin who really did it; when an Admin
     * holding a billet does, the column names the Admin and `*_as_unit` the unit whose billet they held. Null for
     * everything done in one's own name, so every existing row reads as before.
     *
     * Each `*_by_admin` follows the delete rule of the column beside it (`restrictOnDelete`: users are never deleted
     * while they hold history); each `*_as_unit` nulls out with its unit, as the other billet columns do. None of these
     * tables has an enum column, so SQLite's table rebuild loses no CHECK.
     */
    private const COLUMNS = [
        'templates' => 'created',
        'template_versions' => 'created',
        'unit_template_choices' => 'chosen',
        'company_template_policies' => 'set',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $tableName => $verb) {
            Schema::table($tableName, function (Blueprint $table) use ($verb) {
                $table->foreignId("{$verb}_by_admin")->nullable()->after("{$verb}_by")->constrained('users')->restrictOnDelete();
                $table->foreignId("{$verb}_as_unit")->nullable()->after("{$verb}_by_admin")->constrained('units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::COLUMNS, true) as $tableName => $verb) {
            Schema::table($tableName, function (Blueprint $table) use ($verb) {
                $table->dropConstrainedForeignId("{$verb}_as_unit");
                $table->dropConstrainedForeignId("{$verb}_by_admin");
            });
        }
    }
};
