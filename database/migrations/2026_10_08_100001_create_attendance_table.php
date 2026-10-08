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
        // Portal-owned (AD-5/AD-22): manual marks are not re-fetchable from anywhere, so this
        // table must be backed up. One creating writer — 3.3's audited `MarkAttendance` Command,
        // through `AttendanceRepository`. A future auto-capture is a second *source* (`source =
        // auto`), not a second writer of record, and manual wins over auto on conflict.
        //
        // The enum value lists below mirror `App\Models\AttendanceStatus`, `AttendanceSource` and
        // `RollcallSignature` — kept as literals so this migration stays a frozen record of the
        // schema it created, and enforced by the DB so a bad value is refused at the data layer.
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete` on both FKs: a trooper is never hard-deleted (AD-4 flips them
            // inactive), and a practice row is never dropped while marks hang off it.
            $table->foreignId('trooper_id')->constrained('troopers')->restrictOnDelete();
            $table->foreignId('section_practice_id')->constrained('section_practices')->restrictOnDelete();
            // The SP mark (AD-14). Nullable (MVP amendment): a trooper can sign the rollcall
            // before the practice is held and marked. `loa` is derived from the LOA data, never
            // hand-entered (3.4).
            $table->enum('status', ['present', 'missed', 'makeup', 'attached', 'loa'])->nullable();
            // Who produced the SP mark: a leader by hand, or (later) game-server auto-capture.
            // Paired with `status` — both null until the practice is marked, both set after — so
            // a row that so far only carries a rollcall signature claims no mark source, and the
            // manual-wins-over-auto rule (AD-22) never trips over one. `AttendanceRepository`
            // keeps the pairing: only its `markPractice()` writes either column.
            $table->enum('source', ['manual', 'auto'])->nullable();
            // The weekly rollcall-thread signature (MVP amendment), tracked independently of
            // `status`: signing "I will attend" is not attending. Feeds RC % (5.1).
            $table->enum('rollcall', ['unsigned', 'will_attend', 'cannot_attend'])->default('unsigned');
            $table->timestamps();

            // AD-14's grain: exactly one counting mark per trooper per practice.
            $table->unique(['trooper_id', 'section_practice_id']);
            // `trooper_id` leads the unique index above; this covers the per-practice read. MySQL
            // would otherwise reuse the FK's own index for it, but SQLite (the test DB) has none.
            $table->index('section_practice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
