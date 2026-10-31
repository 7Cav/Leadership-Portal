<?php

namespace App\Commands\Counseling;

use App\Models\User;
use App\Repositories\CounselingDocuments;
use App\Support\Audit\AuditEnvelope;

/**
 * Hand a viewer a record's document (#565), audited every time. Only someone who sees the record
 * ({@see CounselingRecordLoader::visible()}); anyone else is told it does not exist. A record kept with a source document
 * (an upload from before #565, or an import) serves that file as it is, never a regenerated form; a seeded demo record
 * has none ({@see CounselingDocuments::isDemo()}). Every other record serves the official form's fields, which the
 * viewer's browser fills into the regiment's template ({@see CounselingDocument}); nothing generated is kept on the
 * server, so nothing is left to purge.
 *
 * One audited action per download (`counseling.downloaded`, AD-16): the viewer, the record, and the SHA-256 of what was
 * served, as the entry's reason. A source document missing on disk is not found, and nothing is audited.
 */
class ServeCounselingDocumentHandler
{
    public function __construct(
        private readonly CounselingRecordLoader $loader,
        private readonly CounselingDocuments $documents,
        private readonly CounselingFormParties $parties,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     */
    public function handle(User $viewer, ServeCounselingDocument $command): ServedCounselingDocument
    {
        [$record] = $this->loader->visible($viewer, $command->recordId);

        if ($record->hasSourceDocument()) {
            if (CounselingDocuments::isDemo((string) $record->document_path) || ! $this->documents->exists((string) $record->document_path)) {
                throw new CounselingRecordNotFoundException;
            }

            $served = new ServedCounselingDocument($record, (string) $this->documents->sha256((string) $record->document_path), path: $record->document_path);
        } else {
            $form = CounselingDocument::for($record, $this->parties->forRecord($record));
            $served = new ServedCounselingDocument($record, hash('sha256', (string) json_encode($form, JSON_THROW_ON_ERROR)), form: $form);
        }

        $this->audit->record(
            actorId: $viewer->id,
            action: 'counseling.downloaded',
            reason: "sha256:{$served->sha256}",
            reasonRequired: false,
            mutation: fn () => $record,
        );

        return $served;
    }
}
