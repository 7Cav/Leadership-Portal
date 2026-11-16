<?php

use App\Rollcall\TemplateSyntax;
use Illuminate\Support\Facades\DB;

// The starter learned LOA on the roll call (`rc_loa`). The migration publishes it as a new active version on every roll
// call template still on the starter 2026_11_19_095000 published, unedited.

function rcLoaMigration(): object
{
    return require database_path('migrations/2026_11_26_120000_publish_rc_loa_on_default_template.php');
}

function rcLoaBody(string $constant): string
{
    return (new ReflectionClassConstant(rcLoaMigration(), $constant))->getValue();
}

/** The regiment default with `$body` as its only version. */
function regimentDefaultOn(string $body): int
{
    $template = DB::table('templates')->whereNull('company_id')->whereNull('section_id')->where('artifact_type', 'rollcall_bbcode')->sole();
    DB::table('template_versions')->where('id', $template->active_version_id)->update(['body' => $body]);

    return $template->id;
}

function activeRollcallVersion(int $templateId): object
{
    return DB::table('template_versions')->where('id', DB::table('templates')->where('id', $templateId)->value('active_version_id'))->sole();
}

it('freezes the previous starter without LOA on the roll call, and the current one with it', function () {
    $previous = (new ReflectionClassConstant(require database_path('migrations/2026_11_19_095000_publish_starter_marks_on_default_template.php'), 'CURRENT'))->getValue();

    expect(rcLoaBody('SEEDED'))->toBe($previous)->not->toContain('rc_loa')
        ->and(rcLoaBody('CURRENT'))->toContain('{{#rc_loa}}LOA{{/rc_loa}}')
        // The starter since is this one with its tags lowercase (2026_11_29_100002).
        ->and((require database_path('migrations/2026_11_29_100002_lowercase_bbcode_in_portal_templates.php'))->lowercaseTags(rcLoaBody('CURRENT')))->toBe(TemplateSyntax::STARTER);
});

it('publishes the starter with LOA on an unchanged regiment default, and rolls back', function () {
    $default = regimentDefaultOn(rcLoaBody('SEEDED'));

    rcLoaMigration()->up();

    expect(activeRollcallVersion($default))->toMatchObject(['version' => 2, 'body' => rcLoaBody('CURRENT'), 'created_by' => null]);

    rcLoaMigration()->down();

    expect(activeRollcallVersion($default))->toMatchObject(['version' => 1, 'body' => rcLoaBody('SEEDED')])
        ->and(DB::table('template_versions')->where('template_id', $default)->count())->toBe(1);
});

it('leaves a regiment default an Admin has edited alone', function () {
    $default = regimentDefaultOn('edited by an Admin');

    rcLoaMigration()->up();

    expect(activeRollcallVersion($default))->toMatchObject(['version' => 1, 'body' => 'edited by an Admin']);
});

it('still finds an unchanged regiment default whose tags were lowercased already, and keeps it lowercase', function () {
    $lower = (string) preg_replace_callback('/\[(\/?)([A-Za-z]+)(?=(?:[=\s][^\[\]]*)?\])/', fn (array $m) => '['.$m[1].strtolower($m[2]), rcLoaBody('SEEDED'));
    $default = regimentDefaultOn($lower);

    rcLoaMigration()->up();

    expect(activeRollcallVersion($default)->body)->toContain('{{#rc_loa}}LOA{{/rc_loa}}')->toContain('[b]')->not->toContain('[B]');
});
