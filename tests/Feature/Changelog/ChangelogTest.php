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
    return ['date' => '2026-10-29', 'body' => 'Changelog page', 'details' => 'You can **read** what changed.', ...$override];
}

/** A one-entry batch, as the Add dialog posts it. */
function batchData(array $override = []): array
{
    $entry = entryData($override);

    return ['date' => $entry['date'], 'entries' => [['body' => $entry['body'], 'details' => $entry['details']]]];
}

function addEntry(User $by, array $override = []): ChangelogEntry
{
    test()->actingAs($by)->post('/changelog', batchData($override))->assertRedirect('/changelog');

    return ChangelogEntry::query()->latest('id')->firstOrFail();
}

it('shows the changelog to everyone grouped by date, newest date first, with editor tools only for editors', function () {
    addEntry($this->editor, ['date' => '2026-10-01', 'body' => 'Older']);
    addEntry($this->editor, ['date' => '2026-10-20', 'body' => 'Newer', 'details' => null]);
    addEntry($this->editor, ['date' => '2026-10-20', 'body' => 'Newer, second']);

    $this->actingAs($this->member)->get('/changelog')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Changelog')
        ->where('isEditor', false)
        ->has('days', 2)
        ->where('days.0.date', '2026-10-20')
        ->where('days.0.entries.0.body', 'Newer')
        ->where('days.0.entries.0.detailsHtml', null)
        ->where('days.0.entries.1.body', 'Newer, second')
        ->where('days.0.entries.1.detailsHtml', fn (string $html) => str_contains($html, '<strong>read</strong>'))
        ->where('days.0.entries.1.details', null)
        ->where('days.1.date', '2026-10-01'));

    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('isEditor', true)
        ->where('days.0.entries.1.details', 'You can **read** what changed.'));
});

it('adds a whole date batch in order in one audited action, leaving blank details empty', function () {
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-30', 'entries' => [
        ['body' => 'First', 'details' => null],
        ['body' => "  Second\n  on one line ", 'details' => '  '],
        ['body' => 'Third', 'details' => 'More'],
    ]])->assertRedirect('/changelog');

    expect(ChangelogEntry::orderBy('id')->get(['body', 'details'])->toArray())->toBe([
        ['body' => 'First', 'details' => null],
        ['body' => 'Second on one line', 'details' => null],
        ['body' => 'Third', 'details' => 'More'],
    ])->and(AuditLogEntry::where('action', 'changelog.created')->count())->toBe(1);
});

it('refuses a batch with no entries, an empty or long change, long details, or too many rows', function (array $entries) {
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-30', 'entries' => $entries])->assertSessionHasErrors();

    expect(ChangelogEntry::count())->toBe(0);
})->with([
    'none' => [[]],
    'empty change' => [[['body' => '']]],
    'long change' => [[['body' => str_repeat('a', 301)]]],
    'long details' => [[['body' => 'A', 'details' => str_repeat('a', 2001)]]],
    'too many' => [array_fill(0, 51, ['body' => 'A'])],
]);

it('lets each editor add, edit and delete, audited, with a reason only for the delete', function () {
    $entry = addEntry($this->editor);
    expect($entry->created_by)->toBe($this->editor->id);

    $this->actingAs($this->otherEditor)->patch("/changelog/{$entry->id}", entryData(['body' => 'Renamed', 'details' => null]))->assertRedirect('/changelog');
    expect($entry->fresh()->body)->toBe('Renamed')
        ->and($entry->fresh()->details)->toBeNull()
        ->and($entry->fresh()->updated_by)->toBe($this->otherEditor->id);

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

    $this->actingAs($user)->post('/changelog', batchData())->assertForbidden();
    $this->actingAs($user)->patch("/changelog/{$entry->id}", entryData())->assertForbidden();
    $this->actingAs($user)->delete("/changelog/{$entry->id}", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->postJson('/changelog/import/preview', ['markdown' => "## 2026-10-01\n- A"])->assertForbidden();
    $this->actingAs($user)->post('/changelog/import', ['markdown' => "## 2026-10-01\n- A", 'skip_duplicates' => true])->assertForbidden();
    $this->actingAs($user)->post('/changelog/outdated/dismiss')->assertForbidden();

    expect(ChangelogEntry::count())->toBe(1)
        ->and(AuditLogEntry::where('action', 'like', 'changelog.%')->count())->toBe(1);
})->with(['member', 'admin']);

it('refuses writes while an Admin acts as an editor', function () {
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->editor->id])->assertRedirect('/home');

    $this->actingAs($this->admin)->post('/changelog', batchData())->assertForbidden();
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

    $this->actingAs($editor)->post('/changelog', batchData())->assertForbidden();
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
    addEntry($this->editor, ['body' => 'Another']);
    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', true));

    // An Admin previewing a unit does not mark their own changelog seen.
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get('/changelog')->assertOk();
    expect(app(ChangelogRepository::class)->seenAt($this->admin->id))->toBeNull();
});

it('never sends a script in the details to the page as HTML', function () {
    addEntry($this->editor, ['details' => "Hello <script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[link](javascript:alert(1))"]);

    $this->actingAs($this->member)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('days.0.entries.0.detailsHtml', fn (string $html) => ! str_contains($html, '<script')
            && ! str_contains($html, '<img')
            && ! str_contains($html, 'javascript:')
            && str_contains($html, 'Hello')));
});

it('previews an import, marking the entries that would be skipped, and saves the rest in one audited batch', function () {
    addEntry($this->editor, ['date' => '2026-10-01', 'body' => 'First', 'details' => null]);
    config(['portal.changelog_deploy_id' => 'build-2']);

    $markdown = "## 2026-10-01\n- first\n- Second\n  Why it changed.\n\n## 2026-10-03\n- Third";

    $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => $markdown])->assertOk()
        ->assertJsonPath('errors', [])
        ->assertJsonPath('entries.0.duplicate', true)
        ->assertJsonPath('entries.1.duplicate', false)
        ->assertJsonPath('entries.1.details', 'Why it changed.')
        ->assertJsonPath('entries.2.body', 'Third');
    expect(ChangelogEntry::count())->toBe(1);

    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])->assertRedirect('/changelog');

    expect(ChangelogEntry::orderBy('date')->orderBy('id')->pluck('body')->all())->toBe(['First', 'Second', 'Third'])
        ->and(ChangelogEntry::where('body', 'Second')->sole()->details)->toBe('Why it changed.')
        ->and(AuditLogEntry::where('action', 'changelog.imported')->count())->toBe(1)
        ->and(app(ChangelogRepository::class)->acknowledgedDeployId())->toBe('build-2');

    // Without skipping, the duplicate is saved too.
    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => false])->assertRedirect('/changelog');
    expect(ChangelogEntry::count())->toBe(6);
});

it('saves nothing from an import with a bad heading', function () {
    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => "## 2026-13-01\n- Bad", 'skip_duplicates' => true])
        ->assertSessionHasErrors('markdown');

    expect(ChangelogEntry::count())->toBe(0)
        ->and(AuditLogEntry::where('action', 'changelog.imported')->count())->toBe(0);
});

it('saves entries as full changelog only unless flagged major, on add, edit and import', function () {
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-30', 'entries' => [
        ['body' => 'Small', 'details' => null],
        ['body' => 'Big', 'details' => null, 'major' => true],
    ]])->assertRedirect('/changelog');

    expect(ChangelogEntry::orderBy('id')->pluck('major', 'body')->all())->toBe(['Small' => false, 'Big' => true]);

    $this->actingAs($this->member)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('days.0.entries.0.major', false)
        ->where('days.0.entries.1.major', true));

    $small = ChangelogEntry::where('body', 'Small')->sole();
    $this->actingAs($this->editor)->patch("/changelog/{$small->id}", entryData(['body' => 'Small', 'major' => true]))->assertRedirect('/changelog');
    expect($small->fresh()->major)->toBeTrue();
    $this->actingAs($this->editor)->patch("/changelog/{$small->id}", entryData(['body' => 'Small']))->assertRedirect('/changelog');
    expect($small->fresh()->major)->toBeFalse();

    $markdown = "## 2026-10-31\n- [major] Imported big\n- Imported small";
    $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => $markdown])
        ->assertJsonPath('entries.0.body', 'Imported big')
        ->assertJsonPath('entries.0.major', true)
        ->assertJsonPath('entries.1.major', false);
    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])->assertRedirect('/changelog');

    expect(ChangelogEntry::where('body', 'Imported big')->sole()->major)->toBeTrue()
        ->and(ChangelogEntry::where('body', 'Imported small')->sole()->major)->toBeFalse();
});

it('sends technical entries to editors always, and to everyone else only while an Admin made them available', function () {
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-30', 'entries' => [
        ['body' => 'Visible', 'details' => null],
        ['body' => 'Dependency updates', 'details' => null, 'technical' => true],
    ]])->assertRedirect('/changelog');
    expect(ChangelogEntry::where('body', 'Dependency updates')->sole()->technical)->toBeTrue();

    // Off by default: a member and an Admin get no technical entry and no toggle.
    foreach (['member', 'admin'] as $who) {
        $this->actingAs($this->{$who})->get('/changelog')->assertInertia(fn (Assert $page) => $page
            ->has('days.0.entries', 1)
            ->where('days.0.entries.0.body', 'Visible')
            ->where('technicalToggle', false));
    }
    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->has('days.0.entries', 2)
        ->where('days.0.entries.1.technical', true)
        ->where('technicalToggle', false)
        ->where('technicalSetting', null));

    $this->actingAs($this->admin)->post('/changelog/settings', ['technical_enabled' => true])->assertRedirect('/changelog');

    $this->actingAs($this->member)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->has('days.0.entries', 2)
        ->where('days.0.entries.1.technical', true)
        ->where('technicalToggle', true)
        ->where('technicalSetting', null));
    $this->actingAs($this->admin)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('technicalSetting.enabled', true));
});

it('lets only an Admin signed in as themselves change the technical setting, audited', function () {
    $this->actingAs($this->member)->post('/changelog/settings', ['technical_enabled' => true])->assertForbidden();
    $this->actingAs($this->editor)->post('/changelog/settings', ['technical_enabled' => true])->assertForbidden();
    $this->actingAs($this->admin)->post('/changelog/settings', [])->assertSessionHasErrors('technical_enabled');
    expect(app(ChangelogRepository::class)->technicalEnabled())->toBeFalse();

    $this->actingAs($this->admin)->post('/changelog/settings', ['technical_enabled' => true])->assertRedirect('/changelog');
    expect(app(ChangelogRepository::class)->technicalEnabled())->toBeTrue()
        ->and(AuditLogEntry::where('action', 'changelog.technical_setting_changed')->sole()->actor_id)->toBe($this->admin->id);

    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/changelog/settings', ['technical_enabled' => false])->assertForbidden();
    expect(app(ChangelogRepository::class)->technicalEnabled())->toBeTrue();
});

it('refuses an entry that is both major and technical, on add and edit', function () {
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-30', 'entries' => [
        ['body' => 'Both', 'details' => null, 'major' => true, 'technical' => true],
    ]])->assertSessionHasErrors('entries');
    expect(ChangelogEntry::count())->toBe(0);

    $entry = addEntry($this->editor);
    $this->actingAs($this->editor)->patch("/changelog/{$entry->id}", entryData(['major' => true, 'technical' => true]))->assertSessionHasErrors('body');
    expect($entry->fresh()->major)->toBeFalse()->and($entry->fresh()->technical)->toBeFalse();

    $this->actingAs($this->editor)->patch("/changelog/{$entry->id}", entryData(['technical' => true]))->assertRedirect('/changelog');
    expect($entry->fresh()->technical)->toBeTrue();
});

it('imports a [technical] marker and leaves technical entries out of the new dot for non-editors', function () {
    Carbon::setTestNow('2026-10-29 10:00:00');
    $this->actingAs($this->member)->get('/changelog')->assertOk();
    Carbon::setTestNow('2026-10-29 10:05:00');

    $markdown = "## 2026-10-31\n- [Technical] Faster page loads behind the scenes";
    $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => $markdown])
        ->assertJsonPath('entries.0.body', 'Faster page loads behind the scenes')
        ->assertJsonPath('entries.0.technical', true)
        ->assertJsonPath('entries.0.major', false);
    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])->assertRedirect('/changelog');

    expect(ChangelogEntry::sole()->technical)->toBeTrue();
    $this->actingAs($this->member)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', false));
});
