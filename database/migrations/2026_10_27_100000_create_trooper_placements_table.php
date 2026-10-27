<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each trooper was placed, and when (decided by the product owner 2026-10-27: after a transfer the new
     * leadership sees the trooper's rollcall signing, section practice attendance and LOAs from before the move). One
     * row per stretch of a primary placement: `placed_from` to `placed_until`, open while `placed_until` is null.
     * Written by the roster sync and the Admin's manual placement whenever `troopers.primary_section_id` changes.
     *
     * Every trooper placed today is backfilled with one open row from the beginning (`placed_from` null): nothing
     * says when they joined their unit, so every week before this table existed is read against it, as before.
     *
     * A deleted unit leaves its rows with no unit (`nullOnDelete`), so the trooper's timeline keeps its dates.
     */
    public function up(): void
    {
        Schema::create('trooper_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trooper_id')->constrained('troopers')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamp('placed_from')->nullable();
            $table->timestamp('placed_until')->nullable();
            $table->string('source', 16);
            $table->timestamps();

            $table->index(['trooper_id', 'placed_until']);
        });

        DB::table('trooper_placements')->insertUsing(
            ['trooper_id', 'unit_id', 'placed_from', 'placed_until', 'source', 'created_at', 'updated_at'],
            DB::table('troopers')
                ->whereNotNull('primary_section_id')
                ->orderBy('id')
                ->select(['id', 'primary_section_id'])
                ->selectRaw('null, null')
                ->selectRaw("coalesce(placement_source, 'sync')")
                ->selectRaw('current_timestamp, current_timestamp'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trooper_placements');
    }
};
