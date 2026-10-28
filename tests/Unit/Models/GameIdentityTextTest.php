<?php

use App\Models\GameIdentityText;

// #450: names and IDs are stored without hidden characters, normalised to NFC and trimmed.

it('removes control and invisible format characters', function (string $hidden) {
    expect(GameIdentityText::clean("Or{$hidden}tega"))->toBe('Ortega');
})->with([
    'zero-width space' => "\u{200B}",
    'zero-width non-joiner' => "\u{200C}",
    'zero-width joiner' => "\u{200D}",
    'byte order mark' => "\u{FEFF}",
    'left-to-right mark' => "\u{200E}",
    'right-to-left mark' => "\u{200F}",
    'right-to-left override' => "\u{202E}",
    'left-to-right embedding' => "\u{202A}",
    'pop directional formatting' => "\u{202C}",
    'first strong isolate' => "\u{2068}",
    'pop directional isolate' => "\u{2069}",
    'soft hyphen' => "\u{00AD}",
    'word joiner' => "\u{2060}",
    'Mongolian vowel separator' => "\u{180E}",
    'tag character' => "\u{E0041}",
    'line separator' => "\u{2028}",
    'Hangul filler' => "\u{3164}",
    'null byte' => "\0",
    'tab' => "\t",
    'newline' => "\n",
    'delete' => "\x7F",
    'C1 control' => "\u{0085}",
]);

it('normalises to NFC, so a decomposed accent is stored composed', function () {
    expect(GameIdentityText::clean("Jose\u{0301}"))->toBe("Jos\u{00E9}")
        ->and(GameIdentityText::isClean("Jose\u{0301}"))->toBeFalse()
        ->and(GameIdentityText::isClean("Jos\u{00E9}"))->toBeTrue();
});

it('trims spaces, Unicode ones included, but keeps the spaces inside a name', function () {
    expect(GameIdentityText::clean("\u{00A0} Big Ortega \u{3000}\n"))->toBe('Big Ortega');
});

it('leaves nothing of a value made only of hidden characters', function () {
    expect(GameIdentityText::clean("\u{200B}\u{200D} \u{FEFF}"))->toBe('');
});

it('never changes printable ASCII, so an ID a server reported cleans to itself', function () {
    $ascii = implode('', array_map('chr', range(0x21, 0x7E)));

    expect(GameIdentityText::clean($ascii))->toBe($ascii)
        ->and(GameIdentityText::clean('a b'))->toBe('a b')
        ->and(GameIdentityText::isClean('76561198012345678'))->toBeTrue()
        ->and(GameIdentityText::isClean('0002105c01b748ada8d8cf5b32e70093'))->toBeTrue()
        ->and(GameIdentityText::isClean('a1b2c3d4-e5f6-7890-abcd-ef1234567890'))->toBeTrue();
});

it('keeps letters, digits and emoji of any alphabet', function () {
    expect(GameIdentityText::clean('Орлов 7 Ωmega 🎯'))->toBe('Орлов 7 Ωmega 🎯');
});

it('turns bytes that are not UTF-8 into a question mark rather than failing', function () {
    expect(GameIdentityText::clean("Ort\xFFega"))->toBe('Ort?ega');
});

it('writes hidden characters out for an Admin to see', function () {
    expect(GameIdentityText::reveal("Or\u{200B}tega\u{202E}"))->toBe('Or[U+200B]tega[U+202E]');
});

it('flags a name that mixes alphabets', function (string $name) {
    expect(GameIdentityText::mixesScripts($name))->toBeTrue();
})->with([
    'Latin with a Cyrillic o' => "Ort\u{043E}ga",
    'Latin with a Greek omicron' => "Ort\u{03BF}ga",
    'Cyrillic with a Latin a' => 'Орлa',
]);

it('does not flag one alphabet with digits, punctuation, symbols and accents', function (string $name) {
    expect(GameIdentityText::mixesScripts($name))->toBeFalse();
})->with([
    'Latin' => 'Ortega',
    'Latin with digits and punctuation' => '[7Cav] Ortega.M_99',
    'Latin with accents' => 'José Müller',
    'Latin with a combining accent' => "Jose\u{0301}",
    'Cyrillic with digits' => 'Орлов 7',
    'Greek' => 'Ωμέγα',
    'Japanese' => '山田たろうタロウ',
    'Korean with Han' => '김철수金',
    'emoji' => 'Ortega 🎯',
    'empty' => '',
]);
