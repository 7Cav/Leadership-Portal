<?php

use App\Docs\Audience;
use App\Docs\GuideLibrary;
use App\Docs\GuideParser;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

// The getting started tutorial (its steps are guides in `resources/docs/tutorial`), docs home's role paths from
// `paths.yaml`, "Recently updated" from `updated`, and the warning call-out. Each test writes its own docs folder.

beforeEach(function () {
    config(['portal.docs' => true]);
    $this->root = sys_get_temp_dir().'/docs-tutorial-'.bin2hex(random_bytes(6));
    $this->docs = $this->root.'/docs';
    File::ensureDirectoryExists($this->docs);
    File::ensureDirectoryExists($this->root.'/guide-img');
    File::put($this->docs.'/sections.yaml', "basics: The basics\nadmin: Admin\ntutorial: Getting started\n");

    tutorialDoc($this->docs, 'basics/sign-in.md', "title: Sign in\nsummary: Sign in.\nsection: basics\norder: 1\naudience: everyone\nupdated: 2026-10-30\n");
    tutorialDoc($this->docs, 'basics/roster.md', "title: Read the roster\nsummary: The roster.\nsection: basics\norder: 2\naudience: everyone\nupdated: 2026-10-31\npage: /roster\n");
    tutorialDoc($this->docs, 'admin/games.md', "title: Games\nsummary: Games.\nsection: admin\norder: 1\naudience: admin\n");
    tutorialDoc($this->docs, 'tutorial/first.md', "title: First step\nsummary: One.\nsection: tutorial\norder: 1\naudience: leader\npage: /home\ntry: Open Home\n", "## Do it\n\n> [!warning] It cannot be undone.\n");
    tutorialDoc($this->docs, 'tutorial/second.md', "title: Second step\nsummary: Two.\nsection: tutorial\norder: 2\naudience: leader\npage: /roster\n");
    File::put($this->docs.'/paths.yaml', implode("\n", [
        'section-leader:',
        '  title: Section Leader',
        '  blurb: Every week.',
        '  audience: leader',
        '  guides: [basics/roster, basics/sign-in]',
        'admin:',
        '  title: Admin',
        '  blurb: Settings.',
        '  audience: admin',
        '  guides: [admin/games]',
        'trooper:',
        '  title: Any trooper',
        '  blurb: The basics.',
        '  audience: everyone',
        '  guides: [basics/sign-in, admin/games]',
    ])."\n");

    app()->instance(GuideLibrary::class, new GuideLibrary(new GuideParser, $this->docs, $this->root.'/guide-img'));

    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);
    Trooper::factory()->create(['cav_id' => 900, 'status' => TrooperStatus::Combat]);
    $this->trooper = User::factory()->create(['cav_id' => 900]);
    UnitCommandMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => 5900]);
    Trooper::factory()->create(['cav_id' => 901, 'cav_position_id' => 5900, 'status' => TrooperStatus::Combat]);
    $this->leader = User::factory()->create(['cav_id' => 901]);
});

afterEach(fn () => File::deleteDirectory($this->root));

function tutorialDoc(string $docs, string $path, string $meta, string $body = "Body.\n"): void
{
    File::ensureDirectoryExists(dirname("{$docs}/{$path}"));
    File::put("{$docs}/{$path}", "---\n{$meta}---\n{$body}");
}

it('has no problem in the tutorial, the paths or the updated dates', function () {
    expect(app(GuideLibrary::class)->problems())->toBe([]);
});

it('opens a tutorial step on its own page, with every step and the steps either side', function () {
    $this->actingAs($this->leader)->get('/docs/tutorial/second')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Docs/Tutorial')
        ->where('guide.title', 'Second step')
        ->where('steps', [
            ['id' => 'first', 'title' => 'First step', 'url' => '/docs/tutorial/first'],
            ['id' => 'second', 'title' => 'Second step', 'url' => '/docs/tutorial/second'],
        ])
        ->where('previous.url', '/docs/tutorial/first')
        ->where('next', null));

    $this->actingAs($this->leader)->get('/docs/tutorial/first')->assertInertia(fn (Assert $page) => $page
        ->where('guide.try', 'Open Home')
        ->where('guide.page', '/home')
        ->where('guide.html', fn (string $html) => str_contains($html, '<blockquote class="callout callout-warning">')
            && str_contains($html, '<strong>Watch out.</strong> It cannot be undone.')));
});

it('sends /docs/tutorial to the first step, or to docs home for a reader with no tutorial', function () {
    $this->actingAs($this->leader)->get('/docs/tutorial')->assertRedirect('/docs/tutorial/first');
    $this->actingAs($this->trooper)->get('/docs/tutorial')->assertRedirect('/docs');
    $this->actingAs($this->trooper)->get('/docs/tutorial/first')->assertNotFound();
});

it('answers 404 on the tutorial while the docs are off', function () {
    config(['portal.docs' => false]);

    $this->actingAs($this->leader)->get('/docs/tutorial')->assertNotFound();
});

it('keeps the tutorial out of the contents and the guide neighbors, but in the search', function () {
    $this->actingAs($this->leader)->get('/docs/basics/roster')->assertInertia(fn (Assert $page) => $page
        ->component('Docs/Show')
        ->where('contents', fn ($contents) => collect($contents)->pluck('key')->all() === ['basics'])
        ->where('next', null)
        ->where('steps', null)
        ->where('tutorial.url', '/docs/tutorial/first')
        ->where('search', fn ($search) => collect($search)->pluck('url')->contains('/docs/tutorial/first')));
});

it('never opens a tutorial step from the "?" help', function () {
    $this->actingAs($this->leader)->get('/docs/help?from=/home')->assertRedirect('/docs');
    $this->actingAs($this->leader)->get('/docs/help?from=/roster')->assertRedirect('/docs/basics/roster?from=%2Froster');
});

it('offers docs home role paths, only the reader may follow, with only the guides they may read', function () {
    $this->actingAs($this->leader)->get('/docs')->assertInertia(fn (Assert $page) => $page
        ->where('paths', [
            ['key' => 'section-leader', 'title' => 'Section Leader', 'blurb' => 'Every week.', 'guides' => [
                ['title' => 'Read the roster', 'summary' => 'The roster.', 'url' => '/docs/basics/roster', 'audience' => 'everyone', 'weekly' => false],
                ['title' => 'Sign in', 'summary' => 'Sign in.', 'url' => '/docs/basics/sign-in', 'audience' => 'everyone', 'weekly' => false],
            ]],
            ['key' => 'trooper', 'title' => 'Any trooper', 'blurb' => 'The basics.', 'guides' => [
                ['title' => 'Sign in', 'summary' => 'Sign in.', 'url' => '/docs/basics/sign-in', 'audience' => 'everyone', 'weekly' => false],
            ]],
        ])
        ->where('tutorial.steps.0.id', 'first'));

    $this->actingAs($this->trooper)->get('/docs')->assertInertia(fn (Assert $page) => $page
        ->where('paths', fn ($paths) => collect($paths)->pluck('key')->all() === ['trooper'])
        ->where('tutorial', null));
});

it('names a paths.yaml entry that is not a guide, and a bad updated or try, as problems', function () {
    File::put($this->docs.'/paths.yaml', "oops:\n  title: Oops\n  blurb: Broken.\n  audience: leader\n  guides: [basics/missing]\n");
    tutorialDoc($this->docs, 'basics/bad.md', "title: Bad\nsummary: Bad.\nsection: basics\norder: 3\naudience: everyone\nupdated: last week\ntry: Open it\n");
    $library = new GuideLibrary(new GuideParser, $this->docs, $this->root.'/guide-img');

    expect($library->problems())->toContain('paths.yaml: oops lists basics/missing, which is not a guide')
        ->toContain('basics/bad.md: `updated` must be a date written as 2026-10-30')
        ->toContain('basics/bad.md: `try` needs a `page` to open')
        ->and($library->paths(Audience::Leader))->toBe([]);
});
