<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Rollcall\TemplateSyntax;
use Illuminate\Support\Facades\DB;

// #629: the regiment default was seeded from the starter before it had the excused and make-up marks. The migration
// publishes the current starter as a new active version on every template still on the seeded starter, unedited.

function starterMarksMigration(): object
{
    return require database_path('migrations/2026_11_19_095000_publish_starter_marks_on_default_template.php');
}

function seededStarter(object $migration): string
{
    return (new ReflectionClassConstant($migration, 'SEEDED'))->getValue();
}

/** The regiment default, as the templates migration seeded it before the starter changed. */
function oldRegimentDefault(string $seeded): int
{
    $template = DB::table('templates')->whereNull('company_id')->whereNull('section_id')->where('artifact_type', 'rollcall_bbcode')->sole();
    DB::table('template_versions')->where('id', $template->active_version_id)->update(['body' => $seeded]);

    return $template->id;
}

/** A company template with its versions' bodies, the last one active. */
function companyTemplate(array $bodies): int
{
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    $author = User::factory()->create();
    $templateId = DB::table('templates')->insertGetId(['company_id' => $company->id, 'artifact_type' => 'rollcall_bbcode', 'name' => 'Ours', 'enabled' => true, 'created_by' => $author->id, 'created_at' => now(), 'updated_at' => now()]);

    foreach ($bodies as $i => $body) {
        $versionId = DB::table('template_versions')->insertGetId(['template_id' => $templateId, 'company_id' => $company->id, 'artifact_type' => 'rollcall_bbcode', 'version' => $i + 1, 'body' => $body, 'created_by' => $author->id, 'created_at' => now()]);
    }
    DB::table('templates')->where('id', $templateId)->update(['active_version_id' => $versionId]);

    return $templateId;
}

function activeVersion(int $templateId): object
{
    return DB::table('template_versions')->where('id', DB::table('templates')->where('id', $templateId)->value('active_version_id'))->sole();
}

it('freezes the starter as the templates migration seeded it, without the new marks', function () {
    $seeded = seededStarter(starterMarksMigration());

    expect($seeded)->not->toContain('sp_excused')->not->toContain('rc_excused')->toContain('sp_attached')
        ->and((new ReflectionClassConstant(starterMarksMigration(), 'CURRENT'))->getValue())->toBe(TemplateSyntax::STARTER);
});

it('publishes the current starter as v2 of an unchanged regiment default and of an unchanged copy, and rolls back', function () {
    $migration = starterMarksMigration();
    $seeded = seededStarter($migration);
    $default = oldRegimentDefault($seeded);
    $copy = companyTemplate([$seeded]);
    $edited = companyTemplate([$seeded, $seeded.'[B]Ours[/B]']);

    $migration->up();

    expect(activeVersion($default))->toMatchObject(['version' => 2, 'body' => TemplateSyntax::STARTER, 'created_by' => null])
        ->and(activeVersion($default)->body)->toContain('{{#sp_excused}}EX{{/sp_excused}}')->toContain('{{#rc_makeup_requested}}MU{{/rc_makeup_requested}}')->toContain('{{#rc_excused}}EX{{/rc_excused}}')
        ->and(activeVersion($copy))->toMatchObject(['version' => 2, 'body' => TemplateSyntax::STARTER])
        ->and(activeVersion($edited)->body)->toBe($seeded.'[B]Ours[/B]');

    $migration->up(); // again: nothing left on the seeded starter
    expect(DB::table('template_versions')->where('template_id', $default)->count())->toBe(2);

    $migration->down();

    expect(activeVersion($default))->toMatchObject(['version' => 1, 'body' => $seeded])
        ->and(DB::table('template_versions')->where('template_id', $default)->count())->toBe(1)
        ->and(activeVersion($copy)->version)->toBe(1)
        ->and(activeVersion($edited)->version)->toBe(2);
});

it('leaves a regiment default an Admin has edited alone', function () {
    $migration = starterMarksMigration();
    $default = oldRegimentDefault(seededStarter($migration));
    DB::table('template_versions')->where('id', DB::table('templates')->where('id', $default)->value('active_version_id'))->update(['body' => 'edited by an Admin']);

    $migration->up();

    expect(activeVersion($default))->toMatchObject(['version' => 1, 'body' => 'edited by an Admin']);
});

it('leaves a template that went back to the seeded starter from a later version alone', function () {
    $migration = starterMarksMigration();
    $seeded = seededStarter($migration);
    $template = companyTemplate([$seeded, 'later']);
    DB::table('templates')->where('id', $template)->update(['active_version_id' => DB::table('template_versions')->where('template_id', $template)->where('version', 1)->value('id')]);

    $migration->up();

    expect(activeVersion($template)->version)->toBe(1);
});

it('leaves a regiment default already on the current starter alone', function () {
    $default = DB::table('templates')->whereNull('company_id')->whereNull('section_id')->where('artifact_type', 'rollcall_bbcode')->value('id');

    starterMarksMigration()->up();

    expect(activeVersion($default))->toMatchObject(['version' => 1, 'body' => TemplateSyntax::STARTER]);
});
