<?php

use App\Commands\Changelog\ChangelogImportParser;

// The bulk import's parser: `## YYYY-MM-DD` starts a date, each `- ` bullet under it is one entry, and an indented block
// under a bullet is that entry's additional information.

function parseChangelog(string $markdown): array
{
    return (new ChangelogImportParser)->parse($markdown);
}

it('reads each bullet under a date as one entry, with an indented block as its details', function () {
    $result = parseChangelog(
        "Intro to skip\n\n## 2026-10-30\n- First change\n  More about it.\n\n  - a nested list\n- Second change\n\n## 2026-10-29\n* Older change\n"
    );

    expect($result['errors'])->toBe([])
        ->and($result['entries'])->toBe([
            ['line' => 4, 'date' => '2026-10-30', 'body' => 'First change', 'details' => "More about it.\n\n- a nested list"],
            ['line' => 8, 'date' => '2026-10-30', 'body' => 'Second change', 'details' => null],
            ['line' => 11, 'date' => '2026-10-29', 'body' => 'Older change', 'details' => null],
        ]);
});

it('takes text after the date on the heading as the first entry', function (string $separator) {
    $result = parseChangelog("## 2026-10-29 {$separator} Heading change\n- Bullet change");

    expect($result['errors'])->toBe([])
        ->and(array_column($result['entries'], 'body'))->toBe(['Heading change', 'Bullet change']);
})->with(['-', "\u{2013}", "\u{2014}", ':']);

it('keeps a fenced block under a bullet in its details', function () {
    $result = parseChangelog("## 2026-10-01\n- One\n  ```\n  ## not a heading\n  ```\n");

    expect($result['errors'])->toBe([])
        ->and($result['entries'][0]['details'])->toBe("```\n## not a heading\n```");
});

it('names the line of each problem', function (string $markdown, string $message) {
    $result = parseChangelog($markdown);

    expect($result['errors'][0]['message'])->toContain($message);
})->with([
    'not a date' => ["## Release notes\n- Text", 'Line 1: a heading must be a date'],
    'bad date' => ["## 2026-02-30\n- Text", '"2026-02-30" is not a date'],
    'short date' => ["## 2026-2-3\n- Text", 'is not a date'],
    'no entries' => ["## 2026-10-01\n\n## 2026-10-02\n- Text", 'Line 1: the date has no entries'],
    'loose text' => ["## 2026-10-01\nNot a bullet", 'Line 2: start each change with "- "'],
    'empty bullet' => ["## 2026-10-01\n- ", 'Line 2: the bullet has no text'],
    'long change' => ["## 2026-10-01\n- ".str_repeat('a', 301), 'Line 2: the change is longer than 300'],
    'long details' => ["## 2026-10-01\n- A\n  ".str_repeat('a', 2001), 'Line 2: the additional information is longer than 2000'],
    'nothing' => ['Just text', 'No entries found'],
]);
