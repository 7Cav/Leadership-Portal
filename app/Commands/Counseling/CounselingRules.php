<?php

namespace App\Commands\Counseling;

use Carbon\CarbonImmutable;

/**
 * The checks every counseling write makes on what it is given (#381), whatever the form already checked: a date not in
 * the future, a reason, and for a document a real PDF of at most 10 MB, judged by its bytes, never by its name.
 */
final class CounselingRules
{
    public const REASON_MAX = 2000;

    public const DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * How long a filing counts as a repeat of an identical one (same trooper, type, date, reason and document): a
     * double submit, refused so it neither stores a second copy nor counts twice toward the 90-day rules.
     */
    public const REPEAT_WINDOW_MINUTES = 10;

    /** The earliest date a record may carry: older paperwork can be filed, nothing from before the portal's records. */
    public const EARLIEST = '2000-01-01';

    /**
     * @return array{0: CarbonImmutable, 1: string} the date at the start of its day, and the trimmed reason
     *
     * @throws InvalidCounselingRecordException
     */
    public static function checked(CarbonImmutable $counseledOn, string $reason): array
    {
        $day = $counseledOn->startOfDay();

        if ($day->greaterThan(CarbonImmutable::now('UTC')->startOfDay())) {
            throw InvalidCounselingRecordException::futureDate();
        }

        if ($day->lessThan(CarbonImmutable::parse(self::EARLIEST, 'UTC'))) {
            throw InvalidCounselingRecordException::tooOld();
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidCounselingRecordException::noReason();
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw InvalidCounselingRecordException::reasonTooLong();
        }

        return [$day, $reason];
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
