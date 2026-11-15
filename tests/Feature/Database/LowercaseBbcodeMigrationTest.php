<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// 15NOV26: the portal writes every BBCode tag lowercase. The migration lowercases the templates the portal itself wrote,
// never one someone edited.

function lowercaseBbcodeMigration(): object
{
    return require database_path('migrations/2026_11_29_100002_lowercase_bbcode_in_portal_templates.php');
}

function lcActive(int $templateId): object
{
    return DB::table('template_versions')->where('id', DB::table('templates')->where('id', $templateId)->value('active_version_id'))->sole();
}

it('lowercases tag names only, leaving codes, attributes and text as they were', function () {
    expect(lowercaseBbcodeMigration()->lowercaseTags('[CENTER][B]{{unit}} ROLL CALL[/B][/CENTER][IMG width="9"]{{rank_icon}}[/IMG] [Note text'))
        ->toBe('[center][b]{{unit}} ROLL CALL[/b][/center][img width="9"]{{rank_icon}}[/img] [Note text');
});

it('publishes a lowercase copy of a template the portal wrote, keeps someone\'s edit, and rolls back', function () {
    $default = DB::table('templates')->whereNull('company_id')->whereNull('section_id')->where('artifact_type', 'rollcall_bbcode')->value('id');
    DB::table('template_versions')->where('id', DB::table('templates')->where('id', $default)->value('active_version_id'))
        ->update(['body' => '[CENTER][B]{{unit}}[/B][/CENTER]', 'created_by' => null]);

    $editor = User::factory()->admin()->create();
    $editedId = DB::table('templates')->insertGetId(['company_id' => null, 'artifact_type' => 'rollcall_bbcode', 'name' => 'Ours', 'created_by' => $editor->id, 'created_at' => now(), 'updated_at' => now()]);
    $versionId = DB::table('template_versions')->insertGetId(['template_id' => $editedId, 'artifact_type' => 'rollcall_bbcode', 'version' => 1, 'body' => '[B]Ours[/B]', 'created_by' => $editor->id, 'created_at' => now()]);
    DB::table('templates')->where('id', $editedId)->update(['active_version_id' => $versionId]);
    $before = lcActive($default)->version;

    lowercaseBbcodeMigration()->up();

    expect(lcActive($default))->toMatchObject(['version' => $before + 1, 'body' => '[center][b]{{unit}}[/b][/center]', 'created_by' => null])
        ->and(lcActive($editedId)->body)->toBe('[B]Ours[/B]');

    lowercaseBbcodeMigration()->up(); // again: nothing left to lowercase
    expect(lcActive($default)->version)->toBe($before + 1);

    lowercaseBbcodeMigration()->down();
    expect(lcActive($default))->toMatchObject(['version' => $before, 'body' => '[CENTER][B]{{unit}}[/B][/CENTER]']);
});
