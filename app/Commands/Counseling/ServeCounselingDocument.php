<?php

namespace App\Commands\Counseling;

/**
 * A viewer asks for a record's document (#565). Handled by {@see ServeCounselingDocumentHandler}.
 */
final readonly class ServeCounselingDocument
{
    public function __construct(public int $recordId) {}
}
