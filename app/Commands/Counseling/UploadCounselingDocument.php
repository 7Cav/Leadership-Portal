<?php

namespace App\Commands\Counseling;

/**
 * Copy a counseling record's document to the 7Cav Google Drive (#381). Queued per AD-23 by `UploadCounselingDocumentJob`
 * and handled by {@see UploadCounselingDocumentHandler}. Carries the record id only: the job payload holds no file and
 * no credential.
 */
final readonly class UploadCounselingDocument
{
    public function __construct(public int $recordId) {}
}
