<?php

use App\Models\ArtifactType;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Rollcall\TemplateSyntax;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #375: each company's version history becomes one named template, switched on and the company's active one, so every
// section renders what it did before; and the regiment default is seeded from the starter.

function templatesMigration(): object
{
    // The test runs inside a transaction, where SQLite cannot switch its foreign keys off for the table rebuilds the
    // migration makes; deferring them to the commit is the in-transaction way, which `migrate` itself does not need.
    // MySQL has no PRAGMA and alters its tables in place.
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    return require database_path('migrations/2026_10_29_100000_create_templates_table.php');
}

it('carries each company history over as its one active template, and seeds the regiment default once', function () {
    $alpha = Unit::factory()->create(['type' => UnitType::Company]);
    $bravo = Unit::factory()->create(['type' => UnitType::Company]);
    $author = User::factory()->create();
    $activator = User::factory()->create();

    templatesMigration()->down();

    expect(Schema::hasTable('templates'))->toBeFalse()->and(Schema::hasTable('active_template_versions'))->toBeTrue();

    $versions = [];
    foreach ([[$alpha, 1, 'alpha one'], [$alpha, 2, 'alpha two'], [$bravo, 1, 'bravo one']] as [$company, $number, $body]) {
        $versions[$body] = DB::table('template_versions')->insertGetId([
            'company_id' => $company->id, 'artifact_type' => 'rollcall_bbcode', 'version' => $number, 'body' => $body, 'created_by' => $author->id, 'created_at' => now(),
        ]);
    }
    // Alpha was rolled back to its first version.
    DB::table('active_template_versions')->insert([
        ['company_id' => $alpha->id, 'artifact_type' => 'rollcall_bbcode', 'template_version_id' => $versions['alpha one'], 'activated_by' => $activator->id, 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $bravo->id, 'artifact_type' => 'rollcall_bbcode', 'template_version_id' => $versions['bravo one'], 'activated_by' => $activator->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    templatesMigration()->up();

    $repo = app(TemplateVersionRepository::class);
    $alphaTemplates = $repo->templatesOf($alpha->id, ArtifactType::RollcallBbcode);

    expect(Schema::hasTable('active_template_versions'))->toBeFalse()
        ->and($alphaTemplates)->toHaveCount(1)
        ->and($alphaTemplates->first()->enabled)->toBeTrue()
        ->and($alphaTemplates->first()->name)->toBe('Company template')
        ->and($repo->activeFor($alpha->id, ArtifactType::RollcallBbcode)->body)->toBe('alpha one')
        ->and($repo->historyOf($alphaTemplates->first())->pluck('body')->all())->toBe(['alpha two', 'alpha one'])
        ->and($repo->choiceFor($alpha->id, ArtifactType::RollcallBbcode)->chosen_by)->toBe($activator->id)
        ->and($repo->activeFor($bravo->id, ArtifactType::RollcallBbcode)->body)->toBe('bravo one')
        ->and(DB::table('templates')->whereNull('company_id')->count())->toBe(1)
        ->and($repo->regimentDefault(ArtifactType::RollcallBbcode)->activeVersion->body)->toBe(TemplateSyntax::STARTER);
});

it('rolls back to one template per company, and refuses when a company holds more than one', function () {
    $alpha = Unit::factory()->create(['type' => UnitType::Company]);
    $author = User::factory()->create();
    $repo = app(TemplateVersionRepository::class);
    $repo->author($alpha->id, ArtifactType::RollcallBbcode, 'alpha', $author->id);
    $second = $repo->createTemplate($alpha->id, ArtifactType::RollcallBbcode, 'Second', 'second', $author->id);

    expect(fn () => templatesMigration()->down())->toThrow(RuntimeException::class, 'more than one template');

    DB::table('unit_template_choices')->where('template_id', $second->id)->delete();
    DB::table('templates')->where('id', $second->id)->update(['active_version_id' => null]);
    DB::table('template_versions')->where('template_id', $second->id)->delete();
    DB::table('templates')->where('id', $second->id)->delete();

    templatesMigration()->down();

    expect(DB::table('active_template_versions')->where('company_id', $alpha->id)->count())->toBe(1)
        ->and(DB::table('template_versions')->pluck('body')->all())->toBe(['alpha']);
});
