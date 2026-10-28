<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Developmental Counseling Statements and Letters of Reprimand (#381). Portal-owned (AD-22): the audited
     * `app/Commands/Counseling` Commands are the one writer, through `CounselingRecordRepository`; the Drive upload job
     * only moves `drive_status` along. The signed document itself lives on the private `counseling` disk, never under
     * `public/`; `document_path` is a name the portal made up (a UUID), never the uploader's file name, which is kept
     * apart in `document_name` for the download's Content-Disposition only.
     *
     * Delete rules:
     *  - `trooper_id` restricts: a personnel record must never vanish with a roster cache row (as attendance does).
     *  - `issued_by` and `issued_by_admin` restrict, like a weekly report's sender: the record keeps naming who filed it.
     *    `issued_by_admin` is the Admin acting as `issued_by` (#379), null otherwise.
     *  - `issued_as_unit` (the billet an Admin held when filing, #379) and `issuer_unit_id` (the unit whose command
     *    reached the trooper, which decides who may later correct or delete the record) null out with their unit, as
     *    the other acting-billet columns do. Without an issuing unit only an Admin may change the record.
     */
    public function up(): void
    {
        Schema::create('counseling_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trooper_id')->constrained('troopers')->restrictOnDelete();
            $table->enum('type', ['dcs', 'lor']);
            $table->date('counseled_on');
            $table->text('reason');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by_admin')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_as_unit')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('issuer_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('document_path', 100)->unique();
            $table->string('document_name', 150);
            $table->unsignedInteger('document_size');
            $table->char('document_sha256', 64);
            $table->enum('drive_status', ['pending', 'uploaded', 'failed', 'skipped'])->default('pending');
            $table->string('drive_file_id', 200)->nullable();
            $table->timestamp('drive_attempted_at')->nullable();
            $table->timestamps();

            // The trooper page lists a trooper's records newest first and counts the last 90 days.
            $table->index(['trooper_id', 'counseled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counseling_records');
    }
};
