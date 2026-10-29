<?php

use App\Commands\Changelog\ChangelogImportParser;

// The bulk import's parser: each `## YYYY-MM-DD - Title` heading starts an entry, the text to the next heading is its body.

function parseChangelog(string $markdown): array
{
    return (new ChangelogImportParser)->parse($markdown);
}

it('accepts a hyphen, an en dash, an em dash or a colon after the date', function (string $separator) {
    $result = parseChangelog("## 2026-10-29 {$separator} Title here\nBody line.");

    expect($result['errors'])->toBe([])
        ->and($result['entries'])->toBe([['line' => 1, 'date' => '2026-10-29', 'title' => 'Title here', 'body' => 'Body line.']]);
})->with(['-', "\u{2013}", "\u{2014}", ':']);

it('takes the text up to the next heading as the body, keeping deeper headings and ignoring text before the first', function () {
    $result = parseChangelog("Intro to skip\n\n## 2026-10-01 - One\nFirst.\n\n### Detail\nMore.\n\n## 2026-10-02 - Two\nSecond.\n");

    expect($result['errors'])->toBe([])
        ->and($result['entries'][0]['body'])->toBe("First.\n\n### Detail\nMore.")
        ->and($result['entries'][1])->toMatchArray(['line' => 9, 'date' => '2026-10-02', 'title' => 'Two', 'body' => 'Second.']);
});

it('does not start an entry on a heading inside a code block', function () {
    $result = parseChangelog("## 2026-10-01 - One\n```\n## not a heading\n```\n");

    expect($result['errors'])->toBe([])->and($result['entries'])->toHaveCount(1);
});

it('names the line of a bad heading, an impossible date, a missing title or an empty body', function (string $markdown, string $message) {
    $result = parseChangelog($markdown);

    expect($result['errors'][0]['message'])->toContain($message);
})->with([
    'no date' => ["## Release notes\nText", 'Line 1: a heading must look like'],
    'bad date' => ["## 2026-02-30 - Title\nText", '"2026-02-30" is not a date'],
    'short date' => ["## 2026-2-3 - Title\nText", 'is not a date'],
    'no title' => ["## 2026-10-01 -\nText", 'no title'],
    'no body' => ["## 2026-10-01 - Title\n\n## 2026-10-02 - Other\nText", 'Line 1: the entry has no text'],
    'nothing' => ['Just text', 'No entries found'],
]);
