<?php

namespace App\Commands\Counseling;

use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

/**
 * Import a checked batch (#565), off the request path. The batch's rows are judged again now, exactly as the check
 * judged them ({@see ImportCounselingRecordsHandler}), so a row imported since, or a trooper gone from the roster since,
 * is caught, and the Drive files the check downloaded are reused. The batch is deleted when the import ends, stored or
 * not. Only the Admin who checked the batch imports it.
 */
class ImportCounselingBatchHandler
{
    public function __construct(
        private readonly ImportStaging $staging,
        private readonly UserRepository $users,
        private readonly ImportCounselingRecordsHandler $import,
    ) {}

    /**
     * @throws ImportRefusedException the batch is gone
     * @throws AuthorizationException the actor is gone, not an Admin, or not the one who checked the batch
     */
    public function handle(ImportCounselingBatch $command): ImportReport
    {
        $manifest = $this->staging->manifest($command->batch) ?? throw ImportRefusedException::batchGone();
        $actor = $this->users->find($command->actorId);

        if ($actor === null || (int) $manifest['actor_id'] !== $actor->id) {
            throw new AuthorizationException('Only the Admin who checked this import may run it.');
        }

        try {
            $report = $this->import->handle($actor, new ImportCounselingRecords(
                rows: ImportCsv::parse($this->staging->csv($command->batch)),
                files: $this->staging->files($command->batch),
                fields: $this->staging->fields($command->batch),
                downloadDir: $this->staging->downloadDir($command->batch),
                dryRun: false,
                picks: $command->picks,
            ));
        } finally {
            $this->staging->delete($command->batch);
        }

        Log::info('Counseling import finished.', ['batch' => $command->batch, 'actor' => $actor->id, 'summary' => $report->summary()]);

        return $report;
    }
}
