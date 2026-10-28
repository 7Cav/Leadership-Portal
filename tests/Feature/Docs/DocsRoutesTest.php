<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Docs\GuideLibrary;
use App\Docs\GuideParser;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// #409: `/docs` and `/documentation` open the guides behind the `portal.docs` switch, for signed-in serving members,
// showing each viewer only their audience's guides. A guide for someone else is a 404, like one that does not exist.

beforeEach(function () {
    config(['portal.docs' => true]);
    app()->instance(GuideLibrary::class, new GuideLibrary(new GuideParser, base_path('tests/Fixtures/docs'), base_path('tests/Fixtures/docs')));

    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);

    Trooper::factory()->create(['cav_id' => 900, 'status' => TrooperStatus::Combat]);
    $this->trooper = User::factory()->create(['cav_id' => 900]);

    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 5900]);
    Trooper::factory()->create(['cav_id' => 901, 'cav_position_id' => 5900, 'status' => TrooperStatus::Combat]);
    $this->leader = User::factory()->create(['cav_id' => 901]);

    $this->admin = User::factory()->admin()->create();
});

it('sends a visitor who is not signed in to the login page', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/docs', '/docs/basics/sign-in']);

it('answers 404 on every docs route while the docs are switched off, even for an Admin', function (string $url) {
    config(['portal.docs' => false]);

    $this->actingAs($this->admin)->get($url)->assertNotFound();
})->with(['/docs', '/docs/basics/sign-in', '/docs/admin/games']);

it('opens docs home with the viewer\'s own sections and Start here items', function () {
    $this->actingAs($this->leader)->get('/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Docs/Index')
        ->where('audience', 'leader')
        ->has('contents', 2)
        ->where('contents.0.key', 'basics')
        ->where('contents.1.key', 'weekly-rollcall')
        ->where('contents.1.guides.0', ['title' => "Run this week's rollcall", 'summary' => 'Mark attendance, confirm, post.', 'url' => '/docs/weekly-rollcall/run-rollcall', 'audience' => 'leader', 'weekly' => true])
        ->where('checklist', [
            ['id' => 'basics/sign-in', 'label' => 'Sign in with your 7cav.us account', 'url' => '/docs/basics/sign-in'],
            ['id' => 'weekly-rollcall/run-rollcall', 'label' => 'Run your first rollcall', 'url' => '/docs/weekly-rollcall/run-rollcall'],
        ]));
});

it('opens a guide with its body, steps, neighbors and breadcrumb', function () {
    $this->actingAs($this->leader)->get('/docs/weekly-rollcall/run-rollcall')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Docs/Show')
        ->where('guide.title', 'Run this week\'s rollcall')
        ->where('guide.section', ['key' => 'weekly-rollcall', 'title' => 'Weekly rollcall'])
        ->where('guide.audience', 'leader')
        ->where('guide.page', '/rollcall')
        ->where('guide.weekly', true)
        ->where('guide.minutes', 5)
        ->where('feedbackUrl', null)
        ->where('guide.checklist', 'weekly-rollcall/run-rollcall')
        ->where('guide.headings.0.id', 'open-rollcall-and-pick-your-section')
        ->where('guide.html', fn (string $html) => str_contains($html, '<strong>Why this matters.</strong>'))
        ->where('previous.url', '/docs/basics/sign-in')
        ->where('next', null)
        ->where('crumb', 'Run this week\'s rollcall'));
});

it('answers 404 for a guide outside the viewer\'s audience, exactly as for one that does not exist', function (string $who, string $url) {
    $this->actingAs($this->{$who})->get($url)->assertNotFound();
})->with([
    'trooper, leader guide' => ['trooper', '/docs/weekly-rollcall/run-rollcall'],
    'trooper, admin guide' => ['trooper', '/docs/admin/games'],
    'leader, admin guide' => ['leader', '/docs/admin/games'],
    'unknown slug' => ['admin', '/docs/basics/nope'],
    'unknown section' => ['admin', '/docs/nope/sign-in'],
    'guide in the wrong section' => ['admin', '/docs/admin/sign-in'],
    'not a slug' => ['admin', '/docs/Basics/sign-in'],
]);

it('lets each audience open its own guides', function (string $who, string $url) {
    $this->actingAs($this->{$who})->get($url)->assertOk();
})->with([
    'trooper, everyone guide' => ['trooper', '/docs/basics/sign-in'],
    'leader, leader guide' => ['leader', '/docs/weekly-rollcall/run-rollcall'],
    'admin, admin guide' => ['admin', '/docs/admin/games'],
    'admin, leader guide' => ['admin', '/docs/weekly-rollcall/run-rollcall'],
]);

it('sends /documentation on to the matching /docs path, permanently', function (string $from, string $to) {
    $this->actingAs($this->trooper)->get($from)->assertStatus(301)->assertRedirect($to);
})->with([
    ['/documentation', '/docs'],
    ['/documentation/basics/sign-in', '/docs/basics/sign-in'],
]);

it('follows view as: an Admin previewing a trooper reaches only the trooper\'s guides', function () {
    app(ViewAs::class)->enter($this->admin, new ViewAsTarget(ViewAsKind::User, $this->trooper->id));

    $this->actingAs($this->admin)->get('/docs/admin/games')->assertNotFound();
    $this->actingAs($this->admin)->get('/docs/weekly-rollcall/run-rollcall')->assertNotFound();
    $this->actingAs($this->admin)->get('/docs')->assertInertia(fn (Assert $page) => $page->where('audience', 'everyone')->has('contents', 1));
});

it('follows view as: an Admin previewing a section reads it as that section\'s leader', function () {
    app(ViewAs::class)->enter($this->admin, new ViewAsTarget(ViewAsKind::Unit, $this->section->id));

    $this->actingAs($this->admin)->get('/docs/weekly-rollcall/run-rollcall')->assertOk();
    $this->actingAs($this->admin)->get('/docs/admin/games')->assertNotFound();
});

it('opens the viewer\'s guide for the page the "?" help was pressed on, and says where they came from', function () {
    $this->actingAs($this->leader)->get('/docs/help?from=/rollcall/12')->assertRedirect('/docs/weekly-rollcall/run-rollcall?from=%2Frollcall%2F12');
    $this->actingAs($this->admin)->get('/docs/help?from=/settings/games')->assertRedirect('/docs/admin/games?from=%2Fsettings%2Fgames');
});

it('opens docs home from a page with no guide the viewer may read, never a 404', function (string $who, string $from) {
    $this->actingAs($this->{$who})->get('/docs/help?from='.urlencode($from))->assertRedirect('/docs');
})->with([
    'trooper on a leader page' => ['trooper', '/rollcall'],
    'leader on an Admin page' => ['leader', '/settings/games'],
    'a page with no guide' => ['admin', '/home'],
    'no page given' => ['admin', ''],
    'another site' => ['admin', '//evil.example/rollcall'],
    'a full URL' => ['admin', 'https://evil.example/rollcall'],
    'a tab a browser drops' => ['admin', "/\t/evil.example"],
    'a line break' => ['admin', "/\n/evil.example"],
    'a backslash' => ['admin', '/rollcall\\..'],
]);

it('answers 404 on the "?" help while the docs are off', function () {
    config(['portal.docs' => false]);

    $this->actingAs($this->admin)->get('/docs/help?from=/rollcall')->assertNotFound();
});

it('passes the feedback link S6 set to every docs page', function () {
    config(['portal.docs_feedback_url' => 'https://7cav.us/threads/portal-docs.1/']);

    $this->actingAs($this->trooper)->get('/docs')->assertInertia(fn (Assert $page) => $page->where('feedbackUrl', 'https://7cav.us/threads/portal-docs.1/'));
    $this->actingAs($this->trooper)->get('/docs/basics/sign-in')->assertInertia(fn (Assert $page) => $page->where('feedbackUrl', 'https://7cav.us/threads/portal-docs.1/'));
});

it('shares the docs switch with the shell as features.docs', function (bool $on) {
    config(['portal.docs' => $on]);

    $this->actingAs($this->trooper)->get('/home')->assertInertia(fn (Assert $page) => $page->where('features.docs', $on));
})->with([true, false]);
