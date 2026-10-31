<?php

namespace App\Commands\Counseling;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import a checked batch (#565), off the request path. The batch's rows are judged again now, exactly as the check
 * judged them ({@see ImportCounselingRecordsHandler}), so a row imported since, or a trooper gone from the roster since,
 * is caught, and the Drive files the check downloaded are reused. Only the Admin who checked the batch imports it, and
 * only once: the controller marks it queued, and a run that finds it in any other state does nothing.
 *
 * **The outcome is kept for the Admin.** The run marks the batch running, then records how it ended in
 * {@see ImportStaging} with the final report, row by row, rows skipped only now included ("already imported", "none
 * was picked", "the import stopped here"):
 *  - **finished**: every row is stored or skipped with its reason.
 *  - **refused**: the batch, the CSV or the Admin is no longer valid; nothing was stored.
 *  - **failed**: the run stopped partway ({@see ImportAbortedException}, with the rows stored so far) or could not
 *    start (the staging area, or anything unexpected, which is also rethrown so the queue records the failed job).
 * Then the batch's CSV and PDFs are deleted; the check and the outcome stay until the batch goes stale.
 */
class ImportCounselingBatchHandler
{
    public function __construct(
        private readonly ImportStaging $staging,
        private readonly UserRepository $users,
        private readonly ImportCounselingRecordsHandler $import,
    ) {}

    /**
     * Mark a checked batch queued, before its job is dispatched: only its own Admin's, only a checked one, only once.
     *
     * @throws ImportRefusedException the batch is gone, not this Admin's, not checked, or already imported
     * @throws ImportStagingFailed
     */
    public function queue(User $actor, string $batch): void
    {
        $manifest = $this->staging->manifest($batch);

        if ($manifest === null || (int) $manifest['actor_id'] !== $actor->id || $this->staging->plan($batch) === null) {
            throw ImportRefusedException::batchGone();
        }

        if ($this->staging->status($batch) !== null) {
            throw ImportRefusedException::alreadyRun();
        }

        $this->staging->saveStatus($batch, ImportStaging::QUEUED);
    }

    /**
     * @throws ImportRefusedException the batch is gone, or was already imported (nothing is recorded)
     * @throws Throwable an unexpected failure, recorded as failed first
     */
    public function handle(ImportCounselingBatch $command): ?ImportReport
    {
        $manifest = $this->staging->manifest($command->batch) ?? throw ImportRefusedException::batchGone();

        if (($this->staging->status($command->batch)['state'] ?? null) !== ImportStaging::QUEUED) {
            throw ImportRefusedException::alreadyRun();
        }

        $this->staging->saveStatus($command->batch, ImportStaging::RUNNING);

        try {
            $actor = $this->users->find($command->actorId);

            if ($actor === null || (int) $manifest['actor_id'] !== $actor->id) {
                throw new AuthorizationException('Only the Admin who checked this import may run it.');
            }

            $report = $this->import->handle($actor, new ImportCounselingRecords(
                rows: ImportCsv::parse($this->staging->csv($command->batch)),
                files: $this->staging->files($command->batch),
                fields: $this->staging->fields($command->batch),
                downloadDir: $this->staging->downloadDir($command->batch),
                dryRun: false,
                picks: $command->picks,
            ));

            $this->finish($command->batch, ImportStaging::FINISHED, null, $report->toArray());
            Log::info('Counseling import finished.', ['batch' => $command->batch, 'actor' => $actor->id, 'summary' => $report->summary()]);

            return $report;
        } catch (AuthorizationException|ImportRefusedException $e) {
            $this->finish($command->batch, ImportStaging::REFUSED, $e->getMessage());
        } catch (ImportAbortedException $e) {
            // The cause was reported where it happened.
            $this->finish($command->batch, ImportStaging::FAILED, $e->getMessage(), $e->report->toArray());
        } catch (ImportStagingFailed $e) {
            report($e);
            $this->finish($command->batch, ImportStaging::FAILED, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->finish($command->batch, ImportStaging::FAILED, 'The import stopped unexpectedly. Nothing after the last stored record was imported; check the log, then import the CSV again.');

            throw $e;
        }

        return null;
    }

    /**
     * Mark a batch failed from outside the run, when the queue gave up on it (a timeout, a worker that died): the run
     * itself never got to say how it ended. A batch already finished, refused or failed is left as it is.
     */
    public function markFailed(string $batch, string $reason): void
    {
        try {
            if (in_array($this->staging->status($batch)['state'] ?? null, [ImportStaging::QUEUED, ImportStaging::RUNNING], true)) {
                $this->finish($batch, ImportStaging::FAILED, $reason);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Record how the run ended and delete the batch's CSV and PDFs. A status that cannot be written is reported; the
     * files are still cleared.
     *
     * @param  array<string, mixed>|null  $report
     */
    private function finish(string $batch, string $state, ?string $reason, ?array $report = null): void
    {
        try {
            $this->staging->saveStatus($batch, $state, $reason, $report);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $this->staging->clearFiles($batch);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
