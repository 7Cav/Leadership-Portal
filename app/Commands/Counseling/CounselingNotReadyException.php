<?php

namespace App\Commands\Counseling;

use RuntimeException;

/**
 * A draft is not ready to share (#565): `$missing` says, per field, what is still needed, in the order the page shows
 * the steps, so the page can list it and mark each field.
 */
class CounselingNotReadyException extends RuntimeException
{
    /**
     * @param  array<string, string>  $missing  field => what is needed
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('The record is not ready to share: '.implode(' ', $missing));
    }
}
