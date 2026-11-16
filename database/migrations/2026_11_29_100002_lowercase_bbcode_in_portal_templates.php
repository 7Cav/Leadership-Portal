<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every BBCode tag the portal writes is lowercase from 15NOV26 (`[b]`, `[table]`), so a post no longer mixes `[B]` from
 * the starter with `[b]` from someone's own lines. The forum reads either case, so nothing renders differently. This
 * publishes a lowercase copy of each roll call template whose active version the portal itself wrote (no author: the
 * seeded regiment default and the starters later migrations published) and still holds an uppercase tag. A version
 * someone wrote is theirs and is left alone. The old version stays in the history, and `down()` makes it active again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->activeVersions() as $row) {
                $body = str_replace("\r\n", "\n", (string) $row->body);
                $lower = self::lowercaseTags($body);

                if ($row->created_by !== null || $lower === $body) {
                    continue;
                }

                $now = now();
                $latest = (int) DB::table('template_versions')->where('template_id', $row->id)->max('version');
                $versionId = DB::table('template_versions')->insertGetId([
                    'template_id' => $row->id,
                    'company_id' => $row->company_id,
                    'section_id' => $row->section_id,
                    'artifact_type' => $row->artifact_type,
                    'version' => $latest + 1,
                    'body' => $lower,
                    'created_by' => null,
                    'created_at' => $now,
                ]);

                DB::table('templates')->where('id', $row->id)->update(['active_version_id' => $versionId, 'updated_at' => $now]);
            }
        });
    }

    /** Back to the version before, on each template still on a lowercase copy this added. */
    public function down(): void
    {
        DB::transaction(function () {
            foreach ($this->activeVersions() as $row) {
                $previous = DB::table('template_versions')->where('template_id', $row->id)->where('version', (int) $row->version - 1)->first(['id', 'body', 'created_by']);

                if ($row->created_by !== null || $previous === null || $previous->created_by !== null
                    || self::lowercaseTags(str_replace("\r\n", "\n", (string) $previous->body)) !== str_replace("\r\n", "\n", (string) $row->body)
                    || str_replace("\r\n", "\n", (string) $previous->body) === str_replace("\r\n", "\n", (string) $row->body)) {
                    continue;
                }

                DB::table('templates')->where('id', $row->id)->update(['active_version_id' => $previous->id]);

                // A report sent on the lowercase version keeps it: the version it froze is history.
                if (! DB::table('weekly_reports')->where('template_version_id', $row->version_id)->exists()) {
                    DB::table('template_versions')->where('id', $row->version_id)->delete();
                }
            }
        });
    }

    /** The name of every BBCode tag in `$body`, opening or closing, lowercased; everything else as it was. */
    public static function lowercaseTags(string $body): string
    {
        // A tag closes with "]" before any other "[": "[Note text" is text, not a tag.
        return (string) preg_replace_callback('/\[(\/?)([A-Za-z]+)(?=(?:[=\s][^\[\]]*)?\])/', fn (array $m) => '['.$m[1].strtolower($m[2]), $body);
    }

    /** @return list<object> */
    private function activeVersions(): array
    {
        return DB::table('templates')
            ->join('template_versions', 'template_versions.id', '=', 'templates.active_version_id')
            ->where('templates.artifact_type', 'rollcall_bbcode')
            ->select('templates.id', 'template_versions.company_id', 'template_versions.section_id', 'templates.artifact_type', 'template_versions.id as version_id', 'template_versions.version', 'template_versions.body', 'template_versions.created_by')
            ->get()
            ->all();
    }
};
