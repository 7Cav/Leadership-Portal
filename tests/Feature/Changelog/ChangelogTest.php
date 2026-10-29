<?php

use App\Models\AuditLogEntry;
use App\Models\ChangelogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\ChangelogRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// The changelog: open to everyone, written only by the changelog editors signed in as themselves, every write audited.

beforeEach(function () {
    config(['portal.changelog_editors' => ['Grayson.J', 'Laui.M'], 'portal.changelog_deploy_id' => 'build-1']);

    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->section = app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);

    $serving = function (int $cavId, array $attributes) {
        Trooper::factory()->create(['cav_id' => $cavId, 'status' => TrooperStatus::Combat]);

        return User::factory()->create(['cav_id' => $cavId, ...$attributes]);
    };

    // Matched without case.
    $this->editor = $serving(801, ['username' => 'grayson.j']);
    $this->otherEditor = $serving(802, ['username' => 'Laui.M']);
    $this->member = $serving(803, ['username' => 'Pike.S']);
    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S']);
});

function entryData(array $override = []): array
{
    return ['date' => '2026-10-29', 'title' => 'Changelog page', 'body' => 'You can **read** what changed.', ...$override];
}

function addEntry(User $by, array $override = []): ChangelogEntry
{
    test()->actingAs($by)->post('/changelog', entryData($override))->assertRedirect('/changelog');

    return ChangelogEntry::query()->latest('id')->firstOrFail();
}

it('shows the changelog to everyone, newest first, with editor tools only for editors', function () {
    addEntry($this->editor, ['date' => '2026-10-01', 'title' => 'Older']);
    addEntry($this->editor, ['date' => '2026-10-20', 'title' => 'Newer']);

    $this->actingAs($this->member)->get('/changelog')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Changelog')
        ->where('isEditor', false)
        ->where('entries.0.title', 'Newer')
        ->where('entries.1.title', 'Older')
        ->where('entries.0.body', null));

    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('isEditor', true)
        ->where('entries.0.body', 'You can **read** what changed.'));
});

it('lets each editor add, edit and delete, audited, with a reason only for the delete', function () {
    $entry = addEntry($this->editor);
    expect($entry->created_by)->toBe($this->editor->id);

    $this->actingAs($this->otherEditor)->patch("/changelog/{$entry->id}", entryData(['title' => 'Renamed']))->assertRedirect('/changelog');
    expect($entry->fresh()->title)->toBe('Renamed')->and($entry->fresh()->updated_by)->toBe($this->otherEditor->id);

    $this->actingAs($this->editor)->delete("/changelog/{$entry->id}", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->editor)->delete("/changelog/{$entry->id}", ['reason' => 'Duplicate'])->assertRedirect('/changelog');
    expect(ChangelogEntry::count())->toBe(0);

    expect(AuditLogEntry::where('action', 'changelog.created')->sole()->actor_id)->toBe($this->editor->id)
        ->and(AuditLogEntry::where('action', 'changelog.updated')->sole()->actor_id)->toBe($this->otherEditor->id)
        ->and(AuditLogEntry::where('action', 'changelog.deleted')->sole()->reason)->toBe('Duplicate');
});

it('refuses every write to anyone who is not an editor, an Admin included', function (string $who) {
    $entry = addEntry($this->editor);
    $user = $this->{$who};

    $this->actingAs($user)->post('/changelog', entryData())->assertForbidden();
    $this->actingAs($user)->patch("/changelog/{$entry->id}", entryData())->assertForbidden();
    $this->actingAs($user)->delete("/changelog/{$entry->id}", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->postJson('/changelog/import/preview', ['markdown' => "## 2026-10-01 - A\nB"])->assertForbidden();
    $this->actingAs($user)->post('/changelog/import', ['markdown' => "## 2026-10-01 - A\nB", 'skip_duplicates' => true])->assertForbidden();
    $this->actingAs($user)->post('/changelog/outdated/dismiss')->assertForbidden();

    expect(ChangelogEntry::count())->toBe(1)
        ->and(AuditLogEntry::where('action', 'like', 'changelog.%')->count())->toBe(1);
})->with(['member', 'admin']);

it('refuses writes while an Admin acts as an editor', function () {
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->editor->id])->assertRedirect('/home');

    $this->actingAs($this->admin)->post('/changelog', entryData())->assertForbidden();
    $this->actingAs($this->admin)->post('/changelog/outdated/dismiss')->assertForbidden();
    $this->actingAs($this->admin)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('isEditor', false)
        ->where('changelogOutdated', false));

    expect(ChangelogEntry::count())->toBe(0);
});

it('refuses writes during a view-as preview', function () {
    // An Admin who is also an editor, so the preview is theirs to start.
    config(['portal.changelog_editors' => ['Grayson.J', 'Laui.M', 'Hale.S']]);
    $editor = $this->admin;
    $this->actingAs($editor)->post('/changelog/outdated/dismiss')->assertRedirect();
    $this->actingAs($editor)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

    $this->actingAs($editor)->post('/changelog', entryData())->assertForbidden();
    expect(ChangelogEntry::count())->toBe(0);
});

it('shows the outdated bar to editors only after the deploy id changes, and clears it on a new entry for both', function () {
    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogOutdated', false));
    $this->actingAs($this->editor)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogOutdated', true));

    addEntry($this->otherEditor);
    $this->actingAs($this->editor)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogOutdated', false));
    expect(app(ChangelogRepository::class)->acknowledgedDeployId())->toBe('build-1');

    config(['portal.changelog_deploy_id' => 'build-2']);
    $this->actingAs($this->otherEditor)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogOutdated', true));
});

it('clears the outdated bar for both editors on a dismiss, audited', function () {
    $this->actingAs($this->editor)->post('/changelog/outdated/dismiss')->assertRedirect();

    $this->actingAs($this->otherEditor)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogOutdated', false));
    expect(AuditLogEntry::where('action', 'changelog.outdated_dismissed')->sole()->actor_id)->toBe($this->editor->id);
});

it('never shows the outdated bar without a deploy id', function () {
    config(['portal.changelog_deploy_id' => false]);

    $this->actingAs($this->editor)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogOutdated', false));
});

it('shows the new dot until the user opens the changelog, and not after a view-as visit', function () {
    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', false));

    Carbon::setTestNow('2026-10-29 10:00:00');
    addEntry($this->editor);
    Carbon::setTestNow('2026-10-29 10:05:00');

    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', true));
    $this->actingAs($this->member)->get('/changelog')->assertOk();
    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', false));

    Carbon::setTestNow('2026-10-29 11:00:00');
    addEntry($this->editor, ['title' => 'Another']);
    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', true));

    // An Admin previewing a unit does not mark their own changelog seen.
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get('/changelog')->assertOk();
    expect(app(ChangelogRepository::class)->seenAt($this->admin->id))->toBeNull();
});

it('never sends a script in a body to the page as HTML', function () {
    addEntry($this->editor, ['body' => "Hello <script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[link](javascript:alert(1))"]);

    $this->actingAs($this->member)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('entries.0.html', fn (string $html) => ! str_contains($html, '<script')
            && ! str_contains($html, '<img')
            && ! str_contains($html, 'javascript:')
            && str_contains($html, 'Hello')));
});

it('previews an import, marking the entries that would be skipped, and saves the rest in one audited batch', function () {
    addEntry($this->editor, ['date' => '2026-10-01', 'title' => 'First']);
    config(['portal.changelog_deploy_id' => 'build-2']);

    $markdown = "## 2026-10-01 - first\nAgain.\n\n## 2026-10-02 \u{2013} Second\nTwo.\n\n## 2026-10-03: Third\nThree.";

    $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => $markdown])->assertOk()
        ->assertJsonPath('errors', [])
        ->assertJsonPath('entries.0.duplicate', true)
        ->assertJsonPath('entries.1.duplicate', false)
        ->assertJsonPath('entries.2.title', 'Third');
    expect(ChangelogEntry::count())->toBe(1);

    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])->assertRedirect('/changelog');

    expect(ChangelogEntry::orderBy('date')->pluck('title')->all())->toBe(['First', 'Second', 'Third'])
        ->and(AuditLogEntry::where('action', 'changelog.imported')->count())->toBe(1)
        ->and(app(ChangelogRepository::class)->acknowledgedDeployId())->toBe('build-2');

    // Without skipping, the duplicate is saved too.
    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => false])->assertRedirect('/changelog');
    expect(ChangelogEntry::count())->toBe(6);
});

it('saves nothing from an import with a bad heading', function () {
    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => "## 2026-13-01 - Bad\nText", 'skip_duplicates' => true])
        ->assertSessionHasErrors('markdown');

    expect(ChangelogEntry::count())->toBe(0)
        ->and(AuditLogEntry::where('action', 'changelog.imported')->count())->toBe(0);
});
