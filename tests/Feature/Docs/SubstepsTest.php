<?php

use App\Docs\GuideParser;

// A numbered list under a numbered step is lettered A, B, C, so "1 Record the signatures" never reads as 1 then 1, 2, 3.

function substepsHtml(string $body): string
{
    return (new GuideParser)->parse('basics', 'a-guide', "---\ntitle: A guide\nsummary: For.\nsection: basics\norder: 1\naudience: everyone\n---\n{$body}")['guide']->html;
}

it('letters a numbered list that sits under a numbered step', function () {
    expect(substepsHtml("## Record the signatures\n\n1. Open it.\n2. Mark it.\n"))
        ->toContain('<ol type="A" class="docs-substeps">');
});

it('keeps numbers for a numbered list before the first step, and under a level-3 heading of no step', function () {
    expect(substepsHtml("1. One.\n2. Two.\n\n## A step\n\nText.\n"))->toContain("<ol>\n<li>One.")
        ->not->toContain('docs-substeps');
    expect(substepsHtml("# Title\n\n1. One.\n"))->not->toContain('docs-substeps');
});

it('letters every numbered list in a step, past a level-3 heading, and leaves bullet lists alone', function () {
    $html = substepsHtml("## A step\n\n- a bullet\n\n### Detail\n\n1. One.\n");

    expect($html)->toContain('<ul>')->toContain('<ol type="A" class="docs-substeps">');
});
