<?php

use Database\Seeders\CounselingSeeder;

// The seeded counseling documents have to open in a browser's PDF viewer, which needs the objects and a
// cross-reference table whose offsets point at them.

it('writes a seeded document as a well-formed PDF', function () {
    $pdf = CounselingSeeder::minimalPdf('Seeded LOR for Novak.E (test)');

    expect($pdf)->toStartWith('%PDF-1.4')
        ->toEndWith("%%EOF\n")
        ->toContain('(Seeded LOR for Novak.E \(test\)) Tj');

    preg_match('/startxref\n(\d+)\n/', $pdf, $start);
    expect(substr($pdf, (int) $start[1], 4))->toBe('xref');

    preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $entries);
    expect($entries[1])->toHaveCount(5);
    foreach ($entries[1] as $i => $offset) {
        expect(substr($pdf, (int) $offset, strlen(($i + 1).' 0 obj')))->toBe(($i + 1).' 0 obj');
    }
});
