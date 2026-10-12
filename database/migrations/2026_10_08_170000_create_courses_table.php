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
        // Portal-owned (AD-5/AD-22): no external course authority exists, so this catalog is
        // manually seeded, must be backed up, and has exactly one creating writer — the audited
        // `CreateCourse` Command, through `CourseRepository`. There is deliberately no `cav_id` /
        // source key. `category` holds the course's `AreaOfOperation` value (`general` for the
        // courses that belong to no single AO, such as NCOA).
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            // The short label shown in matrix column headers (`BCT`, `NCOA`). UNIQUE so a duplicate
            // is rejected by the database itself, never silently coalesced into an upsert.
            $table->string('code')->unique();
            $table->string('name');
            // An enum column, like `units.type` and `attendance.status`: the database refuses a value
            // `AreaOfOperation` does not know, instead of letting one bad row make the cast throw for
            // the whole catalog. A new AO is a new migration, as for any other enum here.
            $table->enum('category', [
                'general', 'battlefield-6', 'arma-3', 'arma-reforger', 'hll-ww2',
                'hll-ww2-console', 'hll-vietnam', 'dcs-world', 'squad', 'foxhole',
            ])->default('general');
            $table->text('description')->nullable();
            // Column/row order for milpac panels and the readiness matrix. Not unique: ties are
            // broken on `code` by `CourseRepository::all()`, so the order is never a shuffle.
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
