<?php

use App\Rollcall\TemplateSyntax;

// Story 3.10: the grammar an Admin's rollcall template must follow. The cases live in tests/Fixtures/bbcode-templates.json
// and the browser renderer (resources/js/lib/bbcode.js) is tested against the same file, so the two accept and refuse
// the same templates with the same messages.

function bbcodeCases(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/bbcode-templates.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('accepts every well-formed template in the shared cases', function () {
    foreach (bbcodeCases()['valid'] as $case) {
        expect(TemplateSyntax::check($case['template']))->toBeNull("accepted: {$case['name']}");
    }
});

it('refuses every malformed template in the shared cases, saying what is wrong', function () {
    foreach (bbcodeCases()['invalid'] as $case) {
        $error = TemplateSyntax::check($case['template']);

        expect($error)->not->toBeNull("refused: {$case['name']}")
            ->and(str_contains($error, $case['error']))->toBeTrue("message for: {$case['name']} was: {$error}");
    }
});

it('has a case for every name it lets a template use, so the lists cannot drift from the tests', function () {
    $valid = implode("\n", array_column(bbcodeCases()['valid'], 'template'));

    foreach ([...TemplateSyntax::TOP_STRINGS, ...TemplateSyntax::ROW_STRINGS, ...TemplateSyntax::ROW_FLAGS] as $name) {
        expect($valid)->toContain($name);
    }
});

it('ships a starter template that follows its own grammar and uses every status', function () {
    expect(TemplateSyntax::check(TemplateSyntax::STARTER))->toBeNull();

    foreach (['sp_present', 'sp_missed', 'sp_makeup', 'sp_attached', 'sp_loa', 'sp_unmarked', 'rc_yes', 'rc_no', 'rc_unsigned'] as $flag) {
        expect(TemplateSyntax::STARTER)->toContain("{{#{$flag}}}");
    }

    expect(TemplateSyntax::STARTER)->toContain('{{^troopers}}');
});

it('knows exactly one list and two filters', function () {
    expect(TemplateSyntax::LISTS)->toBe(['troopers'])->and(TemplateSyntax::FILTERS)->toBe(['upper', 'lower']);
});

it('checks a very long run of spaces inside a tag quickly, and never reads a failed match as valid', function () {
    $started = microtime(true);
    $error = TemplateSyntax::check('{{'.str_repeat(' ', 19000).'!');

    expect($error)->not->toBeNull()->and(microtime(true) - $started)->toBeLessThan(0.5);
    expect(TemplateSyntax::check('{{'.str_repeat(' ', 19000).'#troopers}}x'))->toContain('is never closed');
});

it('cannot be made to run code: nothing but its fixed names is accepted', function (string $attack) {
    expect(TemplateSyntax::check($attack))->not->toBeNull();
})->with([
    'php' => ['{{ system("ls") }}'],
    'a function call' => ['{{unit()}}'],
    'an attribute' => ['{{troopers.0.name}}'],
    'a script-style tag' => ['{{#troopers}}{{name}}{{/troopers}}{{ exec }}'],
    'a nested open' => ['{{#troopers}}{{#troopers}}{{/troopers}}{{/troopers}}'],
]);
