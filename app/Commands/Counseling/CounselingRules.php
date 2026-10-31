<?php

namespace App\Commands\Counseling;

use Carbon\CarbonImmutable;

/**
 * The checks every counseling write makes on what it is given (#381, #565), whatever the form already checked: a date
 * not in the future, a reason of bounded length, and for a source document a real PDF of at most 10 MB, judged by its
 * bytes, never by its name.
 */
final class CounselingRules
{
    /** The longest a withdrawal's reason may be. */
    public const REASON_MAX = 2000;

    public const DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;

    /** The earliest date a record may carry: older paperwork can be imported, nothing from before the portal's records. */
    public const EARLIEST = '2000-01-01';

    /**
     * The Date of Letter at the start of its day, neither in the future nor before {@see EARLIEST}.
     *
     * @throws InvalidCounselingRecordException
     */
    public static function checkedDate(CarbonImmutable $counseledOn): CarbonImmutable
    {
        $day = $counseledOn->startOfDay();

        if ($day->greaterThan(CarbonImmutable::now('UTC')->startOfDay())) {
            throw InvalidCounselingRecordException::futureDate();
        }

        if ($day->lessThan(CarbonImmutable::parse(self::EARLIEST, 'UTC'))) {
            throw InvalidCounselingRecordException::tooOld();
        }

        return $day;
    }

    /**
     * A required reason, trimmed and no longer than `$max`.
     *
     * @throws InvalidCounselingRecordException
     */
    public static function checkedReason(?string $reason, int $max = self::REASON_MAX, string $field = 'reason'): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw InvalidCounselingRecordException::reasonRequired($field);
        }

        if (mb_strlen($reason) > $max) {
            throw new InvalidCounselingRecordException($field, "Keep the reason to {$max} characters.");
        }

        return $reason;
    }

    /**
     * A PDF of at most 10 MB: the file must start with the PDF signature (`%PDF-`), the content sniffer must agree,
     * and {@see PdfContentScanner} must find it complete, without a password and without scripts, automatic actions
     * or attachments. The extension and the type the browser claimed play no part here.
     *
     * @throws InvalidCounselingRecordException
     */
    public static function checkDocument(string $path): void
    {
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false || $size === 0) {
            throw InvalidCounselingRecordException::notPdf();
        }

        if ($size > self::DOCUMENT_MAX_BYTES) {
            throw InvalidCounselingRecordException::tooLarge();
        }

        $handle = @fopen($path, 'rb');
        $head = $handle === false ? false : fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($head !== '%PDF-' || $sniffed !== 'application/pdf') {
            throw InvalidCounselingRecordException::notPdf();
        }

        match (PdfContentScanner::problem((string) file_get_contents($path))) {
            null => null,
            PdfContentScanner::INCOMPLETE => throw InvalidCounselingRecordException::incompletePdf(),
            PdfContentScanner::ENCRYPTED => throw InvalidCounselingRecordException::encryptedPdf(),
            PdfContentScanner::ACTIVE => throw InvalidCounselingRecordException::activePdf(),
            default => throw InvalidCounselingRecordException::uncheckedPdf(),
        };
    }

    /**
     * The uploaded file's name, for display only: its last path segment, kept to plain characters, ending `.pdf`.
     */
    public static function displayName(string $clientName): string
    {
        $base = basename(str_replace('\\', '/', $clientName));
        $base = trim((string) preg_replace('/[^A-Za-z0-9 ._()-]+/', '', $base), ' .');
        $base = mb_substr((string) preg_replace('/\.pdf$/i', '', $base), 0, 120);

        return ($base !== '' ? $base : 'document').'.pdf';
    }
}
