<?php

use App\Commands\Counseling\PdfContentScanner;

/** A minimal PDF around `$body`, with the signature up front and the end marker at the end. */
function scannedPdf(string $body): string
{
    return "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n{$body}\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

/** An object stream holding `$objects`, compressed as a PDF writer would. */
function objectStream(string $objects): string
{
    $data = gzcompress($objects);

    return '5 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length '.strlen($data)." >>\nstream\n{$data}\nendstream\nendobj";
}

it('passes a plain PDF, links, a page to open at and image bytes that only look like keys', function (string $body) {
    expect(PdfContentScanner::problem(scannedPdf($body)))->toBeNull();
})->with([
    'plain' => ['2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj'],
    'a web link' => ['3 0 obj << /Type /Annot /A << /S /URI /URI (https://7cav.us) >> >> endobj'],
    'open at a page' => ['/OpenAction [3 0 R /Fit]'],
    'a short name that is not a key' => ['4 0 obj << /Length 9 >> stream'."\nxx/JS/AAx\nendstream endobj"],
    'a compressed stream without actions' => [objectStream('6 0 << /Type /Font /Subtype /Type1 >>')],
]);

it('refuses scripts, automatic actions, attachments and other active content', function (string $body) {
    expect(PdfContentScanner::problem(scannedPdf($body)))->toBe(PdfContentScanner::ACTIVE);
})->with([
    'javascript action' => ['3 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj'],
    'js key alone' => ['3 0 obj << /JS 7 0 R >> endobj'],
    'escaped name' => ['3 0 obj << /S /J#61vaScript >> endobj'],
    'open action dictionary' => ['/OpenAction << /S /GoTo /D [3 0 R /Fit] >>'],
    'open action reference' => ['/OpenAction 9 0 R'],
    'additional actions' => ['3 0 obj << /AA << /O 9 0 R >> >> endobj'],
    'launch' => ['3 0 obj << /S /Launch /F (cmd.exe) >> endobj'],
    'remote go to' => ['3 0 obj << /S /GoToR /F (other.pdf) >> endobj'],
    'embedded go to' => ['3 0 obj << /S /GoToE >> endobj'],
    'submit form' => ['3 0 obj << /S /SubmitForm /F (https://example.test) >> endobj'],
    'import data' => ['3 0 obj << /S /ImportData >> endobj'],
    'attachment' => ['3 0 obj << /Type /EmbeddedFile /Length 0 >> endobj'],
    'attachment list' => ['3 0 obj << /Names << /EmbeddedFiles 8 0 R >> >> endobj'],
    'rich media' => ['3 0 obj << /Subtype /RichMedia >> endobj'],
    'xml form' => ['3 0 obj << /AcroForm << /XFA 8 0 R >> >> endobj'],
    'hidden in an object stream' => [objectStream('6 0 << /S /JavaScript /JS (x) >>')],
    'hidden with an abbreviated filter name' => [str_replace('/FlateDecode', '/Fl', objectStream('6 0 << /OpenAction << /S /Launch >> >>'))],
]);

it('refuses an encrypted PDF', function () {
    $pdf = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R /Encrypt 4 0 R >>\n%%EOF\n";

    expect(PdfContentScanner::problem($pdf))->toBe(PdfContentScanner::ENCRYPTED);
});

it('refuses a file without the end marker near its end', function () {
    expect(PdfContentScanner::problem("%PDF-1.7\n1 0 obj << >> endobj\n"))->toBe(PdfContentScanner::INCOMPLETE)
        ->and(PdfContentScanner::problem("%PDF-1.7\n%%EOF\n".str_repeat(' ', PdfContentScanner::EOF_WINDOW + 1)))->toBe(PdfContentScanner::INCOMPLETE);
});

it('refuses a small stream that inflates past the budget instead of filling memory', function () {
    $bomb = gzcompress(str_repeat("\0", PdfContentScanner::STREAM_BUDGET + 1024), 9);

    expect(strlen($bomb))->toBeLessThan(200 * 1024)
        ->and(PdfContentScanner::problem(scannedPdf("5 0 obj << /Filter /FlateDecode >>\nstream\n{$bomb}\nendstream endobj")))
        ->toBe(PdfContentScanner::UNCHECKED);
});
