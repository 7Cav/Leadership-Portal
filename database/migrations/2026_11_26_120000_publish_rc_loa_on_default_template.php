<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Signed RC column of a trooper on LOA who has not signed printed "-", as if they had simply not signed: the starter
 * had no LOA mark for the roll call, only for the practice. The starter now prints LOA there too (`rc_loa`). Like
 * 2026_11_19_095000, this publishes it as a new version and makes it active on every roll call template still on the
 * starter that migration published, unchanged; a template someone edited is left alone. Both bodies are frozen here.
 */
return new class extends Migration
{
    /** `TemplateSyntax::STARTER` as 2026_11_19_095000 published it. */
    private const SEEDED = <<<'BBCODE'
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

    /** `TemplateSyntax::STARTER` with LOA on the roll call. */
    private const CURRENT = <<<'BBCODE'
[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH]{{#fireteams_shown}}[TH]Role[/TH]{{/fireteams_shown}}[TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD]{{#fireteams_shown}}[TD]{{role}}[/TD]{{/fireteams_shown}}[TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_makeup_requested}}MU{{/rc_makeup_requested}}{{#rc_excused}}EX{{/rc_excused}}{{#rc_loa}}LOA{{/rc_loa}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_excused}}EX{{/sp_excused}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
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
                    'body' => self::currentLike((string) $template->body),
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
            // Tag case aside: the portal's own templates may have been lowercased already (2026_11_29_100002).
            ->filter(fn (object $row) => self::sameBody((string) $row->body, $body))
            ->filter(fn (object $row) => ! $addedHere || ($row->created_by === null && (int) $row->version > 1 && self::sameBody((string) DB::table('template_versions')
                ->where('template_id', $row->id)->where('version', (int) $row->version - 1)->value('body'), self::SEEDED)))
            ->values()
            ->all();
    }

    /** Whether two bodies are the same but for line endings and the case of BBCode tag names. */
    private static function sameBody(string $a, string $b): bool
    {
        return self::tagsLower(str_replace("\r\n", "\n", $a)) === self::tagsLower(str_replace("\r\n", "\n", $b));
    }

    /** `$body` with every BBCode tag name lowercase, as the portal writes them from 15NOV26. */
    private static function tagsLower(string $body): string
    {
        return (string) preg_replace_callback('/\[(\/?)([A-Za-z]+)(?=(?:[=\s][^\[\]]*)?\])/', fn (array $m) => '['.$m[1].strtolower($m[2]), $body);
    }

    /** `CURRENT` in the case of the body it replaces: lowercase when that one had been lowercased already. */
    private static function currentLike(string $replaced): string
    {
        $replaced = str_replace("\r\n", "\n", $replaced);

        return self::tagsLower($replaced) === $replaced && $replaced !== str_replace("\r\n", "\n", self::SEEDED) ? self::tagsLower(self::CURRENT) : self::CURRENT;
    }
};
