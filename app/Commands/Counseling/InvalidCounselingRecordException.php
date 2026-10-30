<?php

namespace App\Commands\Counseling;

use RuntimeException;

/**
 * A counseling record could not be saved as given (#381). `$field` names the form field, so the controller can show
 * the message next to it.
 */
class InvalidCounselingRecordException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function futureDate(): self
    {
        return new self('counseled_on', 'The date cannot be in the future.');
    }

    public static function tooOld(): self
    {
        return new self('counseled_on', 'The date is too far back.');
    }

    public static function noReason(): self
    {
        return new self('reason', 'Give the reason for the record.');
    }

    public static function reasonTooLong(): self
    {
        return new self('reason', 'Keep the reason under '.CounselingRules::REASON_MAX.' characters.');
    }

    public static function overturnReasonTooLong(): self
    {
        return new self('reason', 'Keep the reason for overturning to '.OverturnCounselingRecordHandler::REASON_MAX.' characters.');
    }

    public static function notPdf(): self
    {
        return new self('document', 'Attach the signed document as a PDF.');
    }

    public static function incompletePdf(): self
    {
        return new self('document', 'This PDF looks incomplete or damaged. Save it again and attach the new copy.');
    }

    public static function encryptedPdf(): self
    {
        return new self('document', 'This PDF has a password or encryption. Attach a copy without one.');
    }

    public static function activePdf(): self
    {
        return new self('document', 'This PDF has scripts, attachments or actions that run on their own, which the portal does not accept. Print it to a new PDF and attach that copy.');
    }

    public static function uncheckedPdf(): self
    {
        return new self('document', 'This PDF could not be checked. Print it to a new PDF and attach that copy.');
    }

    public static function alreadyFiled(): self
    {
        return new self('document', 'This record is already filed.');
    }

    public static function tooLarge(): self
    {
        return new self('document', 'The document must be 10 MB or smaller.');
    }
}
