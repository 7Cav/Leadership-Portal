<?php

namespace App\Commands\Counseling;

use App\Authorization\ScopeResolver;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The counseling import's check (#565): the CSV is read first, so a file that is not the template is refused before
 * anything is kept; then the CSV and the PDFs become a batch in {@see ImportStaging}, the dry run runs on them (the
 * Drive links downloaded into the batch, for the import to reuse), and its result is kept beside the batch for the
 * page. Nothing is stored in the portal and nothing is audited: no state changes until the import runs.
 */
class ImportCounselingCheckHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly ImportStaging $staging,
        private readonly ImportCounselingRecordsHandler $import,
    ) {}

    /**
     * @return array{batch: string, plan: array<string, mixed>}
     *
     * @throws AuthorizationException the actor is not an Admin
     * @throws ImportRefusedException the CSV is not the template, or has no rows or too many
     */
    public function handle(User $actor, ImportCounselingCheck $command): array
    {
        if (! $this->scope->isAdmin($actor)) {
            throw new AuthorizationException('Only an Admin may import counseling records.');
        }

        $rows = ImportCsv::parse($command->csv);
        $batch = $this->staging->create($actor->id, $command->csvName, $command->csv, $command->uploads, $command->fields);

        $report = $this->import->handle($actor, new ImportCounselingRecords(
            rows: $rows,
            files: $this->staging->files($batch),
            fields: $this->staging->fields($batch),
            downloadDir: $this->staging->downloadDir($batch),
            dryRun: true,
        ));

        $plan = [
            'batch' => $batch,
            'csvName' => $this->staging->manifest($batch)['csv_name'] ?? '',
            'checkedAt' => now()->toIso8601String(),
            ...$report->toArray(),
        ];
        $this->staging->savePlan($batch, $plan);

        return ['batch' => $batch, 'plan' => $plan];
    }
}
