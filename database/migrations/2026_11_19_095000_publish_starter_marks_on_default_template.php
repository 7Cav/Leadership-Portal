<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #629: the regiment default roll call template was seeded once from `TemplateSyntax::STARTER`
 * (`2026_10_29_100000_create_templates_table`), before the starter learned the excused and make-up marks
 * (`sp_excused`, `rc_makeup_requested`, `rc_excused`). This publishes the current starter as a new version and makes it
 * active on every template still rendering the starter exactly as it was seeded: the regiment default, when an Admin
 * has not edited it or gone back to it from a later version, and a company template whose active version is an unchanged copy of it. A template whose active
 * version says anything else is someone's edit and is left alone. Both bodies are frozen here, so the migration does
 * the same thing whatever the starter becomes later.
 */
return new class extends Migration
{
    /** `TemplateSyntax::STARTER` as the 2026_10_29 migration seeded it. */
    private const SEEDED = <<<'BBCODE'
[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH]{{#fireteams_shown}}[TH]Role[/TH]{{/fireteams_shown}}[TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD]{{#fireteams_shown}}[TD]{{role}}[/TD]{{/fireteams_shown}}[TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_attached}}ATT{{/sp_attached}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
{{/troopers}}{{^troopers}}[TR][TD]No troopers are placed in this section.[/TD][/TR]
{{/troopers}}[/TABLE]
BBCODE;

    /** `TemplateSyntax::STARTER` with the excused and make-up marks. */
    private const CURRENT = <<<'BBCODE'
[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH]{{#fireteams_shown}}[TH]Role[/TH]{{/fireteams_shown}}[TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD]{{#fireteams_shown}}[TD]{{role}}[/TD]{{/fireteams_shown}}[TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_makeup_requested}}MU{{/rc_makeup_requested}}{{#rc_excused}}EX{{/rc_excused}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_excused}}EX{{/sp_excused}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
{{/troopers}}{{^troopers}}[TR][TD]No troopers are placed in this section.[/TD][/TR]
{{/troopers}}[/TABLE]
BBCODE;

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->templatesOn(self::SEEDED) as $template) {
                $latest = (int) DB::table('template_versions')->where('template_id', $template->id)->max('version');

                // Someone went back to the seeded starter from a later version on purpose: theirs to keep.
                if ($latest > (int) $template->version) {
                    continue;
                }

                $now = now();
                $versionId = DB::table('template_versions')->insertGetId([
                    'template_id' => $template->id,
                    'company_id' => $template->company_id,
                    'section_id' => $template->section_id,
                    'artifact_type' => $template->artifact_type,
                    'version' => $latest + 1,
                    'body' => self::CURRENT,
                    'created_by' => null,
                    'created_at' => $now,
                ]);

                DB::table('templates')->where('id', $template->id)->update(['active_version_id' => $versionId, 'updated_at' => $now]);
            }
        });
    }

    /** Puts each template back on the version before, for those still on the version this migration added. */
    public function down(): void
    {
        DB::transaction(function () {
            foreach ($this->templatesOn(self::CURRENT, addedHere: true) as $template) {
                $previous = DB::table('template_versions')->where('template_id', $template->id)->where('version', (int) $template->version - 1)->value('id');

                if ($previous === null) {
                    continue;
                }

                DB::table('templates')->where('id', $template->id)->update(['active_version_id' => $previous]);

                // A report sent on the new version keeps it: the version it froze is history.
                if (! DB::table('weekly_reports')->where('template_version_id', $template->version_id)->exists()) {
                    DB::table('template_versions')->where('id', $template->version_id)->delete();
                }
            }
        });
    }

    /**
     * The roll call templates whose active version's body is `$body`. With `$addedHere`, only those whose active
     * version has no author and follows a version holding the seeded starter: the ones `up()` moved.
     *
     * @return list<object>
     */
    private function templatesOn(string $body, bool $addedHere = false): array
    {
        $rows = DB::table('templates')
            ->join('template_versions', 'template_versions.id', '=', 'templates.active_version_id')
            ->where('templates.artifact_type', 'rollcall_bbcode')
            ->select('templates.id', 'templates.company_id', 'templates.section_id', 'templates.artifact_type', 'template_versions.id as version_id', 'template_versions.version', 'template_versions.body', 'template_versions.created_by')
            ->get();

        return $rows
            // Compared in PHP, so a TEXT column compares the same on SQLite and MySQL, line endings included.
            ->filter(fn (object $row) => str_replace("\r\n", "\n", $row->body) === $body)
            ->filter(fn (object $row) => ! $addedHere || ($row->created_by === null && (int) $row->version > 1 && str_replace("\r\n", "\n", (string) DB::table('template_versions')
                ->where('template_id', $row->id)->where('version', (int) $row->version - 1)->value('body')) === self::SEEDED))
            ->values()
            ->all();
    }
};
