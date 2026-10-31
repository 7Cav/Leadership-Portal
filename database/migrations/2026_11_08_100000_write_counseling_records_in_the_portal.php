<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DCSs and LORs written in the portal, field by field, through the wiki procedure (#565). Portal-owned (AD-22): the
     * audited `app/Commands/Counseling` Commands stay the one writer.
     *
     *  - `status` is the step the leader drives (draft, invited, shared); every later step is worked out on read from
     *    the timestamps. `version` goes up on every change.
     *  - `meeting` holds the three Zulu windows of the meeting invite, `meeting_confirmed` the leader's confirmation that
     *    the meeting took place on the Date of Letter (`counseled_on`), which share needs.
     *  - `snapshot` is Section 1 (rank, name and billet of the trooper and the authority) as it stood when shared.
     *  - `responded_at` / `responded_by`: the trooper's one response. The LOR appeal choice and the trooper's part of the
     *    form stay in `form_data`; `appealed_at` and `appeal_statement` now say they asked to appeal, and why.
     *  - `appeal_unit_id` is the unit whose commander decides the appeal, resolved when shared; it nulls out with its unit.
     *    The decision: `appeal_decided_at`, by whom (restricting, like the issuer), the outcome and its reason, the
     *    authority's rank, name and billet then (`appeal_authority`), and the consequence as first written
     *    (`appeal_original`) when it was amended.
     *  - `source` says where the content came from; `document_*` is now the source document, kept only for an upload or
     *    an import, so it is nullable. `import_key` (a Drive file id or a SHA-256) keeps a re-run import from
     *    duplicating; `needs_review` flags a record whose fields someone still has to fill in.
     *  - The Google Drive copy is gone, with its three columns. Copies already on the Drive stay there.
     *
     * Every record already in the portal keeps its uploaded PDF as its source document and counts as shared and
     * acknowledged when it was filed, so its freeze runs as it did; a DCS kept with its PDF still counts its 30 and 90
     * days from the date it carries (`CounselingRecord::windowStart()`), so no expired record comes back.
     */
    public function up(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->enum('status', ['draft', 'invited', 'shared'])->default('draft')->after('type');
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->enum('source', ['portal', 'upload', 'import'])->default('portal')->after('version');
            $table->boolean('needs_review')->default(false)->after('source');
            $table->string('import_key', 200)->nullable()->unique()->after('needs_review');
            $table->json('meeting')->nullable()->after('form_data');
            $table->timestamp('invited_at')->nullable()->after('meeting');
            $table->boolean('meeting_confirmed')->default(false)->after('invited_at');
            $table->json('snapshot')->nullable()->after('meeting_confirmed');
            $table->timestamp('shared_at')->nullable()->after('snapshot');
            $table->timestamp('withdrawn_at')->nullable()->after('shared_at');
            $table->text('withdraw_reason')->nullable()->after('withdrawn_at');
            $table->timestamp('responded_at')->nullable()->after('withdraw_reason');
            $table->foreignId('responded_by')->nullable()->after('responded_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('appeal_unit_id')->nullable()->after('appeal_statement')->constrained('units')->nullOnDelete();
            $table->timestamp('appeal_decided_at')->nullable()->after('appeal_unit_id');
            $table->foreignId('appeal_decided_by')->nullable()->after('appeal_decided_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('appeal_decided_by_admin')->nullable()->after('appeal_decided_by')->constrained('users')->restrictOnDelete();
            $table->enum('appeal_outcome', ['amended', 'not_amended'])->nullable()->after('appeal_decided_by_admin');
            $table->text('appeal_decision_reason')->nullable()->after('appeal_outcome');
            $table->json('appeal_authority')->nullable()->after('appeal_decision_reason');
            $table->json('appeal_original')->nullable()->after('appeal_authority');

            // The Appeals queue reads the undecided appeals of one unit.
            $table->index(['appeal_unit_id', 'appeal_decided_at']);
        });

        // The Drive copy a record already has becomes its import key, so importing the old Drive folder later finds the
        // copies the portal made itself and never brings them in twice.
        DB::table('counseling_records')->whereNotNull('drive_file_id')->update(['import_key' => DB::raw('drive_file_id')]);

        Schema::table('counseling_records', function (Blueprint $table) {
            // A draft has no date yet, and a record written in the portal has neither a free-text reason nor a file.
            $table->date('counseled_on')->nullable()->change();
            $table->text('reason')->nullable()->change();
            $table->string('document_path', 100)->nullable()->change();
            $table->string('document_name', 150)->nullable()->change();
            $table->unsignedInteger('document_size')->nullable()->change();
            $table->char('document_sha256', 64)->nullable()->change();
            $table->dropColumn(['drive_status', 'drive_file_id', 'drive_attempted_at']);
        });

        DB::table('counseling_records')->update([
            'status' => 'shared',
            'source' => 'upload',
            'shared_at' => DB::raw('created_at'),
            'responded_at' => DB::raw('created_at'),
            'meeting_confirmed' => true,
        ]);
    }

    /**
     * Back to the Drive-era columns. A record without a date or a document (a draft, or one written in the portal) has
     * no place in that shape and is removed; every other record keeps its row.
     */
    public function down(): void
    {
        DB::table('counseling_records')
            ->whereNull('counseled_on')->orWhereNull('reason')->orWhereNull('document_path')
            ->delete();

        Schema::table('counseling_records', function (Blueprint $table) {
            $table->dropIndex(['appeal_unit_id', 'appeal_decided_at']);
            $table->dropConstrainedForeignId('appeal_decided_by_admin');
            $table->dropConstrainedForeignId('appeal_decided_by');
            $table->dropConstrainedForeignId('appeal_unit_id');
            $table->dropConstrainedForeignId('responded_by');
            $table->dropUnique(['import_key']);
            $table->dropColumn([
                'status', 'version', 'source', 'needs_review', 'import_key', 'meeting', 'invited_at', 'meeting_confirmed',
                'snapshot', 'shared_at', 'withdrawn_at', 'withdraw_reason', 'responded_at', 'appeal_decided_at',
                'appeal_outcome', 'appeal_decision_reason', 'appeal_authority', 'appeal_original',
            ]);
        });

        Schema::table('counseling_records', function (Blueprint $table) {
            $table->date('counseled_on')->nullable(false)->change();
            $table->text('reason')->nullable(false)->change();
            $table->string('document_path', 100)->nullable(false)->change();
            $table->string('document_name', 150)->nullable(false)->change();
            $table->unsignedInteger('document_size')->nullable(false)->change();
            $table->char('document_sha256', 64)->nullable(false)->change();
            $table->enum('drive_status', ['pending', 'uploaded', 'failed', 'skipped'])->default('skipped')->after('form_data');
            $table->string('drive_file_id', 200)->nullable()->after('drive_status');
            $table->timestamp('drive_attempted_at')->nullable()->after('drive_file_id');
        });
    }
};
