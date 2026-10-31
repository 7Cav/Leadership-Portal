<?php

use App\Models\AuditLogEntry;
use App\Models\ChangelogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// Delete all and its 72 hour restore: editors only, signed in as themselves, audited; deleted entries are hidden
// everywhere, come back exactly as they were, and are gone for good once the window has passed.

beforeEach(function () {
    config(['portal.changelog_editors' => ['Grayson.J', 'Laui.M'], 'portal.changelog_deploy_id' => 'build-1']);
    Carbon::setTestNow('2026-10-31 18:20:00');

    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->section = app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);

    $serving = function (int $cavId, array $attributes) {
        Trooper::factory()->create(['cav_id' => $cavId, 'status' => TrooperStatus::Combat]);

        return User::factory()->create(['cav_id' => $cavId, ...$attributes]);
    };

    $this->editor = $serving(811, ['username' => 'Grayson.J']);
    $this->otherEditor = $serving(812, ['username' => 'Laui.M']);
    $this->member = $serving(813, ['username' => 'Pike.S']);
    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S']);

    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-01', 'entries' => [
        ['body' => 'Older', 'details' => 'With **details**', 'major' => true],
    ]])->assertRedirect('/changelog');
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-10-20', 'entries' => [
        ['body' => 'Newer'],
        ['body' => 'Behind the scenes', 'technical' => true],
    ]])->assertRedirect('/changelog');
});

afterEach(fn () => Carbon::setTestNow());

/** Every column of every entry, deleted ones included, to compare before and after. */
function changelogSnapshot(): array
{
    return ChangelogEntry::withTrashed()->orderBy('id')->get()
        ->map(fn (ChangelogEntry $entry) => collect($entry->getAttributes())->except('deleted_at')->all())->all();
}

it('deletes every entry in one audited action and hides them everywhere', function () {
    $this->actingAs($this->editor)->post('/changelog/delete-all')
        ->assertRedirect('/changelog')->assertSessionHas('notice', '3 entries deleted');

    expect(ChangelogEntry::count())->toBe(0)
        ->and(ChangelogEntry::onlyTrashed()->count())->toBe(3)
        ->and(AuditLogEntry::where('action', 'changelog.deleted_all')->sole()->actor_id)->toBe($this->editor->id);

    // Not on the page, not in the dot, not in the import's duplicate check.
    $this->actingAs($this->member)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->has('days', 0)->where('pendingRestore', null));
    $this->actingAs($this->otherEditor)->get('/home')->assertInertia(fn (Assert $page) => $page->where('changelogUnseen', false));
    $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => "## 2026-10-20\n- Newer"])
        ->assertOk()->assertJsonPath('entries.0.duplicate', false);
});

it('shows editors when the delete all happened and until when it can be restored', function () {
    $this->actingAs($this->editor)->post('/changelog/delete-all');

    $this->actingAs($this->otherEditor)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('pendingRestore.count', 3)
        ->where('pendingRestore.deletedAt', '2026-10-31T18:20:00Z')
        ->where('pendingRestore.restoreUntil', '2026-11-03T18:20:00Z'));
});

it('restores every entry exactly as it was, audited', function () {
    $before = changelogSnapshot();

    $this->actingAs($this->editor)->post('/changelog/delete-all');
    Carbon::setTestNow('2026-11-02 09:00:00');
    $this->actingAs($this->otherEditor)->post('/changelog/restore')
        ->assertRedirect('/changelog')->assertSessionHas('notice', '3 entries restored');

    expect(changelogSnapshot())->toBe($before)
        ->and(ChangelogEntry::onlyTrashed()->count())->toBe(0)
        ->and(AuditLogEntry::where('action', 'changelog.restored')->sole()->actor_id)->toBe($this->otherEditor->id);

    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('pendingRestore', null)
        ->where('days.0.entries.0.body', 'Newer')
        ->where('days.0.entries.1.technical', true)
        ->where('days.1.entries.0.major', true)
        ->where('days.1.entries.0.details', 'With **details**'));
});

it('folds a second delete all into the pending one, so one restore brings back both', function () {
    $this->actingAs($this->editor)->post('/changelog/delete-all');
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-11-01', 'entries' => [['body' => 'Added after']]]);
    $this->actingAs($this->editor)->post('/changelog/delete-all')->assertSessionHas('notice', '4 entries deleted');

    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page
        ->where('pendingRestore.count', 4)
        ->where('pendingRestore.restoreUntil', '2026-11-04T12:00:00Z'));

    $this->actingAs($this->editor)->post('/changelog/restore');
    expect(ChangelogEntry::count())->toBe(4);
});

it('refuses a delete all with no entries and a restore with nothing to restore', function () {
    $this->actingAs($this->editor)->post('/changelog/restore')->assertSessionHasErrors('changelog');
    $this->actingAs($this->editor)->post('/changelog/delete-all');
    $this->actingAs($this->editor)->post('/changelog/delete-all')->assertSessionHasErrors('changelog');

    expect(AuditLogEntry::where('action', 'changelog.deleted_all')->count())->toBe(1);
});

it('treats deleted entries as gone after 72 hours, even before the purge runs', function () {
    $this->actingAs($this->editor)->post('/changelog/delete-all');

    Carbon::setTestNow('2026-11-03 18:19:59');
    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page->where('pendingRestore.count', 3));

    Carbon::setTestNow('2026-11-03 18:20:00');
    $this->actingAs($this->editor)->get('/changelog')->assertInertia(fn (Assert $page) => $page->where('pendingRestore', null));
    $this->actingAs($this->editor)->post('/changelog/restore')->assertSessionHasErrors('changelog');
    expect(ChangelogEntry::count())->toBe(0);
});

it('purges only entries past the restore window, hourly', function () {
    $this->actingAs($this->editor)->post('/changelog/delete-all');
    Carbon::setTestNow('2026-11-02 18:20:00');
    $this->actingAs($this->editor)->post('/changelog', ['date' => '2026-11-02', 'entries' => [['body' => 'Kept']]]);

    $this->artisan('portal:purge-changelog')->expectsOutputToContain('Removed 0 deleted changelog entries')->assertSuccessful();
    expect(ChangelogEntry::withTrashed()->count())->toBe(4);

    Carbon::setTestNow('2026-11-03 18:20:00');
    $this->artisan('portal:purge-changelog')->expectsOutputToContain('Removed 3 deleted changelog entries')->assertSuccessful();
    expect(ChangelogEntry::withTrashed()->pluck('body')->all())->toBe(['Kept']);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:purge-changelog'));
    expect($event?->expression)->toBe('40 * * * *');
});

it('refuses delete all and restore to anyone who is not an editor, an Admin included', function (string $who) {
    $this->actingAs($this->{$who})->post('/changelog/delete-all')->assertForbidden();
    expect(ChangelogEntry::count())->toBe(3);

    $this->actingAs($this->editor)->post('/changelog/delete-all');
    $this->actingAs($this->{$who})->post('/changelog/restore')->assertForbidden();
    $this->actingAs($this->{$who})->get('/changelog')->assertInertia(fn (Assert $page) => $page->where('pendingRestore', null));

    expect(ChangelogEntry::count())->toBe(0)
        ->and(AuditLogEntry::whereIn('action', ['changelog.deleted_all', 'changelog.restored'])->count())->toBe(1);
})->with(['member', 'admin']);

it('refuses delete all while an Admin acts as an editor', function () {
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->editor->id])->assertRedirect('/home');

    $this->actingAs($this->admin)->post('/changelog/delete-all')->assertForbidden();
    expect(ChangelogEntry::count())->toBe(3);
});

it('refuses delete all and restore during a view-as preview', function () {
    config(['portal.changelog_editors' => ['Grayson.J', 'Laui.M', 'Hale.S']]);
    $this->actingAs($this->admin)->post('/changelog/delete-all')->assertRedirect('/changelog');
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post('/changelog/restore')->assertForbidden();
    expect(ChangelogEntry::count())->toBe(0);
});

it('removes a single deleted entry at once, with no restore', function () {
    $entry = ChangelogEntry::query()->where('body', 'Newer')->sole();
    $this->actingAs($this->editor)->delete("/changelog/{$entry->id}", ['reason' => 'Duplicate'])->assertRedirect('/changelog');

    expect(ChangelogEntry::withTrashed()->find($entry->id))->toBeNull();
});
