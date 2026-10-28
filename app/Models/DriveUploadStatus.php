<?php

namespace App\Models;

/**
 * Where a counseling record's copy on the 7Cav Google Drive stands (#381): `pending` until the queued upload has run,
 * `uploaded` once Drive holds it, `failed` while the upload keeps failing (it is retried), and `skipped` when Google
 * Drive is not configured, in which case the record is kept in the portal only. Backed, like every enum column.
 */
enum DriveUploadStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
