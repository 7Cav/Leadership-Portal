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
        // Portal-owned (AD-5/AD-22): a sent report is the canonical in-portal weekly record
        // (AD-12) and is not re-fetchable from anywhere, so this table must be backed up. Its one
        // writer is `WeeklyReportRepository` — the send path driven by 5.6's audited send Command;
        // no `Sync/` handler ever writes it.
        //
        // The `status` value list mirrors `App\Models\WeeklyReportStatus` — kept as literals so
        // this migration stays a frozen record of the schema it created, and enforced by the DB
        // so a bad value is refused at the data layer.
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            // `restrictOnDelete`: a unit with report history cannot be deleted out from under it —
            // the same protective posture as `section_practices.section_id`. Any echelon reports.
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            // Always a Saturday 00:00 UTC (AD-11), stored as a `datetime` for the same reason as
            // `section_practices.week_start`: `WeeklyReportRepository` converts to UTC before
            // writing and the app timezone is UTC, so it reads back as the same instant.
            $table->dateTime('week_start');
            $table->enum('status', ['draft', 'sent'])->default('draft');
            // The six-KPI snapshot with numerators/denominators and the delta vs last week, stored
            // exactly as handed over. Frozen once `status` is `sent` — a sent report is never
            // recomputed (AD-12). On a draft it is only the last saved copy; the live draft is
            // computed from attendance.
            $table->json('kpis');
            $table->text('notes')->nullable();
            // Send metadata: null on a draft, set together when the report is sent.
            $table->dateTime('sent_at')->nullable();
            // `restrictOnDelete`: the sender is part of the record of what was reported.
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('content_digest')->nullable();
            // The AD-17 report-layout template version resolved at send. No foreign key yet:
            // `template_versions` (story 3.5, deferred for the MVP) does not exist. The constraint
            // is added when that table lands; until then this is null or a bare id.
            $table->unsignedBigInteger('template_version_id')->nullable()
                ->comment('template_versions.id resolved at send (AD-17); FK deferred until 3.5 lands');
            $table->timestamps();

            // AD-12: exactly one report per unit per week — the key that makes send idempotent.
            $table->unique(['unit_id', 'week_start']);
            // `unit_id` leads the unique index above; this covers the reverse FK lookup on SQLite
            // (the test DB), where a foreign key gets no index of its own.
            $table->index('sent_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
