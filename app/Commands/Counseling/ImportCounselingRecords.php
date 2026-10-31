<?php

namespace App\Commands\Counseling;

/**
 * Bring older DCSs and LORs into the portal from their Drive links or PDFs (#565), or, as a dry run, say what each CSV
 * row would become without storing anything. The one Command behind the Admin screen's check and import and the
 * `counseling:import` console command. Handled by {@see ImportCounselingRecordsHandler}.
 *
 *  - `$rows`: the CSV ({@see ImportCsv::parse()}).
 *  - `$files`: the PDFs a row's `file` may name, as lower-cased base name => absolute path on this server.
 *  - `$fields`: what the Admin's browser read from each uploaded PDF, by the same lower-cased base name; empty from
 *    the console, which reads no fields.
 *  - `$downloadDir`: a private directory the Drive downloads are kept in, so the import reuses what the check fetched.
 *  - `$viaConsole`: run from `php artisan counseling:import`, which says so in the audit log and offers no trooper
 *    picker, so a Last.F several troopers share is skipped instead of waiting for a pick.
 *  - `$picks`: on the Admin screen, the trooper the Admin picked for a row whose Last.F several troopers share, as CSV
 *    line => trooper id; only one of that row's own matches counts.
 */
final readonly class ImportCounselingRecords
{
    /**
     * @param  list<ImportRow>  $rows
     * @param  array<string, string>  $files
     * @param  array<string, ImportFields|null>  $fields
     * @param  array<int, int>  $picks
     */
    public function __construct(
        public array $rows,
        public array $files,
        public array $fields,
        public string $downloadDir,
        public bool $dryRun,
        public bool $viaConsole = false,
        public array $picks = [],
    ) {}
}
