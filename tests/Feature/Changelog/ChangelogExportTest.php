<?php

use App\Models\AuditLogEntry;
use App\Models\ChangelogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use App\Repositories\AuditSubject;
use App\Repositories\ChangelogRepository;

// Export writes every entry with its id; an import of that file updates by id instead of adding again.

beforeEach(function () {
    config(['portal.changelog_editors' => ['Grayson.J'], 'portal.changelog_deploy_id' => 'build-1']);
    Trooper::factory()->create(['cav_id' => 801, 'status' => TrooperStatus::Combat]);
    $this->editor = User::factory()->create(['cav_id' => 801, 'username' => 'Grayson.J']);
    Trooper::factory()->create(['cav_id' => 803, 'status' => TrooperStatus::Combat]);
    $this->member = User::factory()->create(['cav_id' => 803, 'username' => 'Pike.S']);

    $this->old = ChangelogEntry::create(['date' => '2026-10-28', 'body' => 'Fixed: The *Roll call* page', 'details' => "Line one.  \n\n```\ncode\n```", 'major' => false, 'technical' => true, 'created_by' => $this->editor->id]);
    $this->first = ChangelogEntry::create(['date' => '2026-10-29', 'body' => 'Added: Changelog', 'details' => null, 'major' => true, 'technical' => false, 'created_by' => $this->editor->id]);
    $this->second = ChangelogEntry::create(['date' => '2026-10-29', 'body' => 'Changed: Sidebar', 'details' => 'More.', 'major' => false, 'technical' => false, 'created_by' => $this->editor->id]);
});

it('exports every entry as import Markdown with its id, to editors only', function () {
    $this->actingAs($this->member)->get('/changelog/export')->assertForbidden();

    $response = $this->actingAs($this->editor)->get('/changelog/export')->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment; filename="changelog-');

    expect($response->getContent())->toBe(<<<MD
    # Changelog

    ## 2026-10-29
    - Changed: Sidebar {#{$this->second->id}}
      More.
    - [major] Added: Changelog {#{$this->first->id}}

    ## 2026-10-28
    - [technical] Fixed: The *Roll call* page {#{$this->old->id}}
      Line one.  

      ```
      code
      ```

    MD);
});

it('reads an export back as all unchanged and saves nothing', function () {
    $markdown = $this->actingAs($this->editor)->get('/changelog/export')->getContent();

    $preview = $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => $markdown])->assertOk()->json();
    expect($preview['errors'])->toBe([])
        ->and(array_column($preview['entries'], 'status'))->toBe(['unchanged', 'unchanged', 'unchanged']);

    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])->assertSessionHasErrors('markdown');
    expect(ChangelogEntry::count())->toBe(3)
        ->and(AuditLogEntry::where('action', 'changelog.imported')->count())->toBe(0);
});

it('rewords an entry by id without adding a new one or counting as new', function () {
    $created = $this->first->fresh()->created_at;
    $this->travel(2)->days();
    $markdown = str_replace('[major] Added: Changelog', 'Added: The *Changelog* page', $this->actingAs($this->editor)->get('/changelog/export')->getContent());

    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])
        ->assertRedirect('/changelog')->assertSessionHas('notice', 'Imported: 1 updated, 2 unchanged');

    $entry = $this->first->fresh();
    expect(ChangelogEntry::count())->toBe(3)
        ->and($entry->body)->toBe('Added: The *Changelog* page')
        ->and($entry->major)->toBeFalse()
        ->and($entry->created_at->equalTo($created))->toBeTrue()
        ->and($entry->updated_at->greaterThan($created))->toBeTrue()
        ->and(app(ChangelogRepository::class)->latestCreatedAt()->getTimestamp())->toBe($created->getTimestamp())
        // A reword alone does not clear the "Changelog outdated" bar.
        ->and(app(ChangelogRepository::class)->acknowledgedDeployId())->toBeNull();

    $audit = AuditLogEntry::where('action', 'changelog.imported')->sole();
    expect($audit->target_type)->toBe('ChangelogEntry')
        ->and((int) $audit->target_id)->toBe($this->first->id);
});

it('refuses an unknown or repeated id, naming the line, and saves nothing', function () {
    $markdown = "## 2026-10-29\n- Ghost {#99999}\n- Again {#{$this->first->id}}\n- Twice {#{$this->first->id}}";

    $preview = $this->actingAs($this->editor)->postJson('/changelog/import/preview', ['markdown' => $markdown])->json();
    expect(array_column($preview['errors'], 'message'))->toBe([
        'Line 2: no entry has the id {#99999}. Remove the id to add it as a new entry.',
        "Line 4: the id {#{$this->first->id}} is also on line 3. Each entry can appear once.",
    ]);

    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])
        ->assertSessionHasErrors(['markdown' => 'Line 2: no entry has the id {#99999}. Remove the id to add it as a new entry.']);
    expect(ChangelogEntry::count())->toBe(3)->and($this->first->fresh()->body)->toBe('Added: Changelog');
});

it('adds a line without an id as today, beside updates', function () {
    $markdown = "## 2026-10-30\n- Added: Export\n\n## 2026-10-29\n- [major] Added: Changelog page {#{$this->first->id}}";

    $this->actingAs($this->editor)->post('/changelog/import', ['markdown' => $markdown, 'skip_duplicates' => true])
        ->assertSessionHas('notice', 'Imported: 1 new, 1 updated');

    expect(ChangelogEntry::count())->toBe(4)
        ->and(ChangelogEntry::where('body', 'Added: Export')->sole()->date->toDateString())->toBe('2026-10-30')
        ->and($this->first->fresh()->body)->toBe('Added: Changelog page')
        ->and(app(ChangelogRepository::class)->acknowledgedDeployId())->toBe('build-1');
});

it('names an entry in plain text in audit labels, without emphasis markers', function () {
    expect(ChangelogEntry::plainText('Fixed *Act as* and **bold** and _Roll call_, not snake_case_name'))
        ->toBe('Fixed Act as and bold and Roll call, not snake_case_name')
        ->and(AuditSubject::describe($this->old))->toContain('"Fixed: The Roll call page"');
});
