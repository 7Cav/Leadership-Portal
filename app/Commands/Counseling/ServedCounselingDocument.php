<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;

/**
 * What a document request serves (#565): the record's source document on disk (`$path`, a PDF kept from an upload or an
 * import), or the official form's fields for the browser to fill (`$form`, {@see CounselingDocument::for()}). `$sha256`
 * is the digest the audit entry carries: of the file, or of the form data as served.
 *
 * @phpstan-import-type Payload from CounselingDocument
 */
final readonly class ServedCounselingDocument
{
    /**
     * @param  Payload|null  $form
     */
    public function __construct(
        public CounselingRecord $record,
        public string $sha256,
        public ?string $path = null,
        public ?array $form = null,
    ) {}

    public function isSourceDocument(): bool
    {
        return $this->path !== null;
    }
}
