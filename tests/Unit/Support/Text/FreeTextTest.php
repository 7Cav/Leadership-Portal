<?php

use App\Support\Text\FreeText;

it('empties a value made only of whitespace or invisible characters', function (string $value) {
    expect(FreeText::clean($value))->toBe('');
})->with([
    'Braille blank' => ["\u{2800}"],
    'zero-width space' => ["\u{200B}\u{200B}"],
    'zero-width non-joiner and joiner' => ["\u{200C}\u{200D}"],
    'word joiner' => ["\u{2060}"],
    'byte order mark' => ["\u{FEFF}"],
    'Hangul fillers' => ["\u{3164}\u{115F}\u{1160}"],
    'soft hyphen' => ["\u{00AD}"],
    'no-break space' => ["\u{00A0}"],
    'bidi controls only' => ["\u{202E}\u{2066}\u{200F}"],
    'a mix with spaces' => [" \u{2800} \u{200B}\n"],
    'already empty' => [''],
]);

it('strips bidi controls from real text', function () {
    expect(FreeText::clean("abc\u{202E}fed\u{202C}"))->toBe('abcfed')
        ->and(FreeText::clean("\u{2067}note\u{2069}"))->toBe('note')
        ->and(FreeText::clean("x\u{061C}y\u{200E}z"))->toBe('xyz');
});

it('keeps a zero-width joiner inside real text, as emoji need it', function () {
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

    expect(FreeText::clean($family))->toBe($family)
        ->and(FreeText::clean("Good week \u{1F44D}"))->toBe("Good week \u{1F44D}");
});

it('leaves ordinary text alone', function () {
    expect(FreeText::clean('Comms tighter.'))->toBe('Comms tighter.')
        ->and(FreeText::clean("line one\nline two"))->toBe("line one\nline two");
});

it('returns invalid UTF-8 unchanged for the validation rules to judge', function () {
    expect(FreeText::clean("\xC3\x28"))->toBe("\xC3\x28");
});
