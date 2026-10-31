<?php

namespace App\Commands\Counseling;

/**
 * One row of the counseling import's CSV (#565), as written: every cell trimmed, an empty string when blank or absent.
 * Nothing here is trusted or resolved yet; {@see ImportCounselingRecordsHandler} decides what the row becomes.
 * `$line` is the row's line in the file, the header being line 1, so the Admin can find it.
 */
final readonly class ImportRow
{
    public function __construct(
        public int $line,
        public string $driveUrl = '',
        public string $file = '',
        public string $type = '',
        public string $trooper = '',
        public string $kind = '',
        public string $issuer = '',
        public string $counseledOn = '',
    ) {}
}
