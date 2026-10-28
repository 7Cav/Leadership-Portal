<?php

use App\Docs\Audience;
use App\Docs\Guide;
use App\Docs\GuideLibrary;
use App\Docs\GuideParser;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

// #408: guides are markdown files with front matter, parsed once, filtered by audience, and checked so a broken guide
// fails the build instead of reaching a reader. Each test writes its own small docs folder.

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/docs-'.bin2hex(random_bytes(6));
    $this->docs = $this->root.'/docs';
    $this->images = $this->root.'/guide-img';
    File::ensureDirectoryExists($this->docs);
    File::ensureDirectoryExists($this->images);
    File::put($this->docs.'/sections.yaml', "basics: The basics\nweekly-rollcall: Weekly rollcall\nadmin: Admin\n");
});

afterEach(fn () => File::deleteDirectory($this->root));

/** @param  array<string, mixed>  $meta */
function docWrite(string $docs, string $path, array $meta, string $body = "Body.\n"): void
{
    $yaml = Yaml::dump($meta, 2, 2);
    File::ensureDirectoryExists(dirname("{$docs}/{$path}"));
    File::put("{$docs}/{$path}", "---\n{$yaml}---\n{$body}");
}

/** @return array<string, mixed> */
function docMeta(string $section, string $audience = 'everyone', array $extra = []): array
{
    return ['title' => 'A guide', 'summary' => 'What it is for.', 'section' => $section, 'order' => 1, 'audience' => $audience, ...$extra];
}

function docLibrary(object $test): GuideLibrary
{
    return new GuideLibrary(new GuideParser, $test->docs, $test->images);
}

/** @param  list<Guide>  $guides */
function docUrls(array $guides): array
{
    return array_map(fn (Guide $guide) => $guide->url(), $guides);
}

it('reads a guide\'s front matter and renders its body', function () {
    docWrite($this->docs, 'weekly-rollcall/run-rollcall.md', docMeta('weekly-rollcall', 'leader', [
        'title' => 'Run this week\'s rollcall',
        'summary' => 'Mark attendance, confirm, post.',
        'order' => 3,
        'page' => '/rollcall',
        'weekly' => true,
        'minutes' => 5,
        'checklist' => ['label' => 'Run your first rollcall', 'audience' => 'leader', 'order' => 2],
    ]), "## Open Rollcall\n\n1. Choose **Rollcall** in the sidebar.\n2. Pick your section.\n\n[Glossary](/docs/basics/glossary)\n");
    docWrite($this->docs, 'basics/glossary.md', docMeta('basics'));

    $guide = docLibrary($this)->find(Audience::Leader, 'weekly-rollcall', 'run-rollcall');

    expect(docLibrary($this)->problems())->toBe([])
        ->and($guide->title)->toBe('Run this week\'s rollcall')
        ->and($guide->summary)->toBe('Mark attendance, confirm, post.')
        ->and($guide->order)->toBe(3)
        ->and($guide->audience)->toBe(Audience::Leader)
        ->and($guide->page)->toBe('/rollcall')
        ->and($guide->weekly)->toBeTrue()
        ->and($guide->minutes)->toBe(5)
        ->and($guide->checklist->label)->toBe('Run your first rollcall')
        ->and($guide->checklist->audience)->toBe(Audience::Leader)
        ->and($guide->checklist->order)->toBe(2)
        ->and($guide->url())->toBe('/docs/weekly-rollcall/run-rollcall')
        ->and($guide->html)->toContain('<h2 id="open-rollcall">Open Rollcall</h2>')
        ->and($guide->html)->toContain('<ol>')
        ->and($guide->html)->toContain('<strong>Rollcall</strong>')
        ->and($guide->html)->toContain('<a href="/docs/basics/glossary">Glossary</a>');
});

it('gives every heading a stable anchor and lists the steps for "On this page"', function () {
    docWrite($this->docs, 'basics/steps.md', docMeta('basics'), "## Open Rollcall\n\nText.\n\n### A detail\n\n## Confirm, then post!\n\n## Open Rollcall\n");

    $guide = docLibrary($this)->find(Audience::Everyone, 'basics', 'steps');

    expect($guide->headings)->toBe([
        ['id' => 'open-rollcall', 'text' => 'Open Rollcall'],
        ['id' => 'confirm-then-post', 'text' => 'Confirm, then post!'],
        ['id' => 'open-rollcall-2', 'text' => 'Open Rollcall'],
    ])->and($guide->html)->toContain('<h3 id="a-detail">A detail</h3>');
});

it('renders the "Why this matters" and "Good to know" call-outs, on the marker\'s line or the next', function () {
    docWrite($this->docs, 'basics/callouts.md', docMeta('basics'), <<<'MD'
        > [!why] The report is built from your confirmed rollcall.

        > [!good-to-know]
        > Troopers on leave are already marked.

        > A plain quote stays a plain quote.
        MD);

    $html = docLibrary($this)->find(Audience::Everyone, 'basics', 'callouts')->html;

    expect($html)->toContain('<blockquote class="callout callout-why">'."\n".'<p><strong>Why this matters.</strong> The report is built from your confirmed rollcall.</p>')
        ->and($html)->toContain('<blockquote class="callout callout-good-to-know">'."\n".'<p><strong>Good to know.</strong> Troopers on leave are already marked.</p>')
        ->and($html)->toContain("<blockquote>\n<p>A plain quote stays a plain quote.</p>")
        ->and($html)->not->toContain('[!');
});

it('renders a table', function () {
    docWrite($this->docs, 'basics/roles.md', docMeta('basics'), "| Who | Can do |\n| --- | --- |\n| Section Leader | Rollcall |\n");

    $html = docLibrary($this)->find(Audience::Everyone, 'basics', 'roles')->html;

    expect($html)->toContain('<table>')->toContain('<th>Who</th>')->toContain('<td>Section Leader</td>');
});

it('never renders raw HTML or a script link from a guide', function () {
    docWrite($this->docs, 'basics/unsafe.md', docMeta('basics'), "<script>alert(1)</script>\n\nHi <b onclick=\"x()\">there</b>.\n\n[Click](javascript:alert(1))\n");

    $html = docLibrary($this)->find(Audience::Everyone, 'basics', 'unsafe')->html;

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('onclick')
        ->and($html)->not->toContain('javascript:');
});

it('shows each audience only its own guides, and treats a hidden guide like a missing one', function () {
    docWrite($this->docs, 'basics/sign-in.md', docMeta('basics', 'everyone', ['order' => 1]));
    docWrite($this->docs, 'basics/glossary.md', docMeta('basics', 'everyone', ['order' => 2]));
    docWrite($this->docs, 'weekly-rollcall/run-rollcall.md', docMeta('weekly-rollcall', 'leader'));
    docWrite($this->docs, 'admin/games.md', docMeta('admin', 'admin'));
    $library = docLibrary($this);

    $tree = fn (Audience $reader) => array_map(fn (array $group) => [$group['section']->key, docUrls($group['guides'])], $library->contents($reader));

    expect($tree(Audience::Everyone))->toBe([['basics', ['/docs/basics/sign-in', '/docs/basics/glossary']]])
        ->and($tree(Audience::Leader))->toBe([
            ['basics', ['/docs/basics/sign-in', '/docs/basics/glossary']],
            ['weekly-rollcall', ['/docs/weekly-rollcall/run-rollcall']],
        ])
        ->and(array_column($tree(Audience::Admin), 0))->toBe(['basics', 'weekly-rollcall', 'admin'])
        ->and($library->find(Audience::Everyone, 'weekly-rollcall', 'run-rollcall'))->toBeNull()
        ->and($library->find(Audience::Leader, 'admin', 'games'))->toBeNull()
        ->and($library->find(Audience::Leader, 'admin', 'nope'))->toBeNull()
        ->and($library->find(Audience::Admin, 'admin', 'games'))->not->toBeNull()
        ->and($library->find(Audience::Admin, 'basics', 'games'))->toBeNull();
});

it('orders sections as sections.yaml lists them and guides by order, then slug', function () {
    docWrite($this->docs, 'weekly-rollcall/b.md', docMeta('weekly-rollcall', 'everyone', ['order' => 1]));
    docWrite($this->docs, 'basics/z.md', docMeta('basics', 'everyone', ['order' => 2]));
    docWrite($this->docs, 'basics/y.md', docMeta('basics', 'everyone', ['order' => 2]));
    docWrite($this->docs, 'basics/x.md', docMeta('basics', 'everyone', ['order' => 5]));
    docWrite($this->docs, 'basics/w.md', docMeta('basics', 'everyone', ['order' => 1]));

    expect(docUrls(docLibrary($this)->guides()))->toBe(['/docs/basics/w', '/docs/basics/y', '/docs/basics/z', '/docs/basics/x', '/docs/weekly-rollcall/b']);
});

it('lists the Start here items each audience may see, in their order', function () {
    docWrite($this->docs, 'basics/sign-in.md', docMeta('basics', 'everyone', ['checklist' => ['label' => 'Sign in', 'audience' => 'everyone', 'order' => 1]]));
    docWrite($this->docs, 'basics/roster.md', docMeta('basics', 'everyone', ['checklist' => ['label' => 'Find your troopers', 'audience' => 'leader', 'order' => 2]]));
    docWrite($this->docs, 'weekly-rollcall/run.md', docMeta('weekly-rollcall', 'leader', ['checklist' => ['label' => 'Run your first rollcall', 'audience' => 'leader', 'order' => 3]]));
    docWrite($this->docs, 'weekly-rollcall/guests.md', docMeta('weekly-rollcall', 'leader'));
    $library = docLibrary($this);

    $labels = fn (Audience $reader) => array_map(fn (Guide $guide) => $guide->checklist->label, $library->checklist($reader));

    expect($labels(Audience::Everyone))->toBe(['Sign in'])
        ->and($labels(Audience::Leader))->toBe(['Sign in', 'Find your troopers', 'Run your first rollcall'])
        ->and($labels(Audience::Admin))->toBe(['Sign in', 'Find your troopers', 'Run your first rollcall']);
});

it('maps each page to the readable guide for the narrowest audience the reader holds', function () {
    docWrite($this->docs, 'basics/roster.md', docMeta('basics', 'everyone', ['page' => '/roster']));
    docWrite($this->docs, 'weekly-rollcall/run.md', docMeta('weekly-rollcall', 'leader', ['page' => '/rollcall']));
    docWrite($this->docs, 'basics/games-everyone.md', docMeta('basics', 'everyone', ['page' => '/settings/games']));
    docWrite($this->docs, 'admin/games.md', docMeta('admin', 'admin', ['page' => '/settings/games']));
    $library = docLibrary($this);

    expect($library->pages(Audience::Everyone))->toEqual(['/roster' => '/docs/basics/roster', '/settings/games' => '/docs/basics/games-everyone'])
        ->and($library->pages(Audience::Leader))->toHaveKey('/rollcall', '/docs/weekly-rollcall/run')
        ->and($library->pages(Audience::Admin)['/settings/games'])->toBe('/docs/admin/games');
});

it('finds the guide the "?" help opens on a portal path', function () {
    docWrite($this->docs, 'basics/roster.md', docMeta('basics', 'everyone', ['page' => '/roster']));
    docWrite($this->docs, 'weekly-rollcall/run.md', docMeta('weekly-rollcall', 'leader', ['page' => '/rollcall']));
    docWrite($this->docs, 'weekly-rollcall/report.md', docMeta('weekly-rollcall', 'leader', ['order' => 2, 'page' => '/reports/{unit}/{week}']));
    docWrite($this->docs, 'weekly-rollcall/rollup.md', docMeta('weekly-rollcall', 'leader', ['order' => 3, 'page' => '/reports/{unit?}']));
    docWrite($this->docs, 'admin/games.md', docMeta('admin', 'admin', ['page' => '/settings/games']));
    $library = docLibrary($this);

    expect($library->guideFor(Audience::Leader, '/rollcall'))->toBe('/docs/weekly-rollcall/run')
        ->and($library->guideFor(Audience::Leader, '/rollcall/12?week=2026-10-24'))->toBe('/docs/weekly-rollcall/run')
        ->and($library->guideFor(Audience::Leader, '/rollcall/'))->toBe('/docs/weekly-rollcall/run')
        ->and($library->guideFor(Audience::Leader, '/reports/4/2026-10-24'))->toBe('/docs/weekly-rollcall/report')
        ->and($library->guideFor(Audience::Leader, '/reports'))->toBe('/docs/weekly-rollcall/rollup')
        ->and($library->guideFor(Audience::Leader, '/reports/4'))->toBe('/docs/weekly-rollcall/rollup')
        ->and($library->guideFor(Audience::Leader, '/roster/7'))->toBe('/docs/basics/roster')
        ->and($library->guideFor(Audience::Leader, '/rollcalls'))->toBeNull()
        ->and($library->guideFor(Audience::Leader, '/home'))->toBeNull()
        ->and($library->guideFor(Audience::Leader, '/settings/games'))->toBeNull()
        ->and($library->guideFor(Audience::Admin, '/settings/games'))->toBe('/docs/admin/games')
        ->and($library->guideFor(Audience::Everyone, '/rollcall'))->toBeNull();
});

it('names every broken guide and leaves it out', function (string $path, array $meta, string $body, string $problem) {
    docWrite($this->docs, 'basics/fine.md', docMeta('basics'));
    docWrite($this->docs, $path, $meta, $body);
    $library = docLibrary($this);

    expect($library->problems())->toContain($problem)
        ->and(docUrls($library->guides()))->toContain('/docs/basics/fine');
})->with([
    'missing key' => ['basics/a.md', ['title' => 'A', 'section' => 'basics', 'order' => 1, 'audience' => 'everyone'], '', 'basics/a.md: missing front-matter key `summary`'],
    'unknown key' => ['basics/a.md', docMeta('basics', 'everyone', ['author' => 'me']), '', 'basics/a.md: unknown front-matter key `author`'],
    'unknown audience' => ['basics/a.md', docMeta('basics', 'officers'), '', 'basics/a.md: `audience` must be everyone, leader or admin'],
    'wrong section' => ['basics/a.md', docMeta('admin'), '', 'basics/a.md: `section` must be `basics`, the folder the guide is in'],
    'order not a number' => ['basics/a.md', docMeta('basics', 'everyone', ['order' => 'first']), '', 'basics/a.md: `order` must be a whole number'],
    'weekly not a yes or no' => ['basics/a.md', docMeta('basics', 'everyone', ['weekly' => 'yes']), '', 'basics/a.md: `weekly` must be true or false'],
    'minutes not a number' => ['basics/a.md', docMeta('basics', 'everyone', ['minutes' => 'five']), '', 'basics/a.md: `minutes` must be a whole number of minutes'],
    'page not a path' => ['basics/a.md', docMeta('basics', 'everyone', ['page' => 'rollcall']), '', 'basics/a.md: `page` must be a portal path starting with /'],
    'checklist key' => ['basics/a.md', docMeta('basics', 'everyone', ['checklist' => ['label' => 'A', 'audience' => 'everyone', 'order' => 1, 'icon' => 'x']]), '', 'basics/a.md: unknown `checklist` key `icon`'],
    'checklist missing' => ['basics/a.md', docMeta('basics', 'everyone', ['checklist' => ['label' => 'A', 'order' => 1]]), '', 'basics/a.md: missing `checklist` key `audience`'],
    'checklist wider' => ['weekly-rollcall/a.md', docMeta('weekly-rollcall', 'leader', ['checklist' => ['label' => 'A', 'audience' => 'everyone', 'order' => 1]]), '', "weekly-rollcall/a.md: `checklist.audience` cannot be wider than the guide's own audience, `leader`"],
    'duplicate slug' => ['admin/fine.md', docMeta('admin', 'admin'), '', 'admin/fine.md: slug `fine` is already used by basics/fine.md'],
    'bad file name' => ['basics/Run_Rollcall.md', docMeta('basics'), '', 'basics/Run_Rollcall.md: the file name must be lowercase words joined by hyphens, ending in .md'],
]);

it('names a guide with no front matter, or front matter that is not YAML', function () {
    File::ensureDirectoryExists($this->docs.'/basics');
    File::put($this->docs.'/basics/bare.md', "# Just text\n");
    File::ensureDirectoryExists($this->docs.'/admin');
    File::put($this->docs.'/admin/broken.md', "---\ntitle: [unclosed\n---\nBody\n");

    $problems = docLibrary($this)->problems();

    expect($problems)->toContain('basics/bare.md: front matter is missing: start the file with a --- block')
        ->and(collect($problems)->contains(fn (string $p) => str_starts_with($p, 'admin/broken.md: front matter is not valid YAML')))->toBeTrue();
});

it('fails a link to a guide that does not exist, but not to one that does or to the docs home', function () {
    docWrite($this->docs, 'basics/glossary.md', docMeta('basics'));
    docWrite($this->docs, 'basics/links.md', docMeta('basics'), "[a](/docs/basics/glossary#cav-week) [b](/docs) [c](/docs/basics/nope) [d](https://7cav.us) [e](/rollcall)\n");

    expect(docLibrary($this)->problems())->toBe(['basics/links.md: links to /docs/basics/nope, which is not a guide']);
});

it('fails an image that is not on disk under guide-img', function () {
    File::put($this->images.'/rollcall-picker.png', 'png');
    docWrite($this->docs, 'basics/shots.md', docMeta('basics'), "![Picker](/guide-img/rollcall-picker.png)\n![Gone](/guide-img/gone.png)\n![Far](https://example.com/x.png)\n![Up](/guide-img/../secret.png)\n");

    expect(docLibrary($this)->problems())->toBe([
        'basics/shots.md: image /guide-img/gone.png is not in public/guide-img',
        'basics/shots.md: image https://example.com/x.png is not in public/guide-img',
        'basics/shots.md: image /guide-img/../secret.png is not in public/guide-img',
    ]);
});

it('fails a section folder that sections.yaml does not list, and a file that is not a guide', function () {
    docWrite($this->docs, 'extras/a.md', docMeta('extras'));
    File::ensureDirectoryExists($this->docs.'/basics');
    File::put($this->docs.'/basics/notes.txt', 'x');
    File::put($this->docs.'/README.md', '# How to write a guide');

    expect(docLibrary($this)->problems())->toBe([
        'extras/: folder is not listed in sections.yaml',
        'basics/notes.txt: only .md guides belong in a section folder, with no subfolders',
    ]);
});

it('parses the files once and again only when one changes', function () {
    $file = $this->docs.'/basics/a.md';
    docWrite($this->docs, 'basics/a.md', docMeta('basics', 'everyone', ['title' => 'First']));
    $stamp = filemtime($file);
    expect(docLibrary($this)->guides()[0]->title)->toBe('First');

    // Same size and time: the cached parse is served, not the file.
    docWrite($this->docs, 'basics/a.md', docMeta('basics', 'everyone', ['title' => 'Other']));
    touch($file, $stamp);
    clearstatcache();
    expect(docLibrary($this)->guides()[0]->title)->toBe('First');

    touch($file, $stamp + 10);
    clearstatcache();
    expect(docLibrary($this)->guides()[0]->title)->toBe('Other');
});
