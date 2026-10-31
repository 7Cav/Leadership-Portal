<?php

namespace App\Commands\Counseling;

/**
 * Import a batch the Admin checked on the screen (#565), queued (`App\Jobs\ImportCounselingRecordsJob`; named, not linked,
 * so no reverse edge to the Job layer is imported).
 * `$picks` is the trooper the Admin picked for each row whose Last.F several troopers share, CSV line => trooper id.
 * Handled by {@see ImportCounselingBatchHandler}.
 */
final readonly class ImportCounselingBatch
{
    /**
     * @param  array<int, int>  $picks
     */
    public function __construct(
        public string $batch,
        public int $actorId,
        public array $picks = [],
    ) {}
}
