<?php

namespace App\Commands\Counseling;

use RuntimeException;

/**
 * The counseling import (#565) refused the whole batch before looking at any row: the CSV is not the template, has no
 * rows or too many, or the batch it names is gone. `$field` names the form field the message belongs beside. A refusal
 * of one row is never this: that row is skipped with its reason and the others go on ({@see ImportRowResult}).
 */
class ImportRefusedException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function notCsv(): self
    {
        return new self('csv', 'This is not the import template: its first line must name the columns drive_url and file. Download the template and fill it in.');
    }

    public static function noRows(): self
    {
        return new self('csv', 'The CSV has no rows to import.');
    }

    public static function tooManyRows(): self
    {
        return new self('csv', 'Import at most '.ImportCsv::MAX_ROWS.' rows at a time. Split the CSV and import each part.');
    }

    public static function batchGone(): self
    {
        return new self('batch', 'This check is no longer there. Upload the CSV and check it again.');
    }
}
