<?php

namespace App\Commands\Counseling;

use App\Authorization\ScopeResolver;
use App\Integration\DriveDownload\DriveDownloadApi;
use App\Integration\DriveDownload\DriveDownloadFailed;
use App\Models\CounselingKind;
use App\Models\CounselingType;
use App\Models\User;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The counseling import (#565): older DCSs and LORs brought in from their Drive links or PDFs, one CSV row each, by an
 * Admin. The dry run says what every row would become; the import stores the same and nothing else. One handler for
 * the Admin screen's check, its queued import and the console command, so all three judge a row alike.
 *
 * **What a row needs** (anything missing skips the row, with the reason):
 *  - Exactly one source: a `drive_url` to one publicly shared Drive file (fetched through {@see DriveDownloadApi}, AD-3),
 *    or a `file` naming an uploaded PDF. Either way the bytes must pass {@see CounselingRules::checkDocument()}.
 *  - The **type**: the CSV's, else the form's (R-042 is a DCS, R-043 an LOR).
 *  - The **date**: the CSV's `counseled_on`, else the form's Date of Letter. A record without one cannot be stored:
 *    its windows, its standing and its freeze all run from it.
 *  - The **trooper**, by Last.F, from the CSV or the form, matching exactly one trooper on the roster. No match skips
 *    the row: `trooper_id` is required, and guessing would put a disciplinary record on someone else. Several matches
 *    wait for the Admin to pick one on the screen ({@see ImportRowResult::REVIEW}, with the candidates); without a
 *    pick, and always from the console, the row is skipped.
 *
 * **What a row may lack** and is still stored:
 *  - The **kind**: without one it is ruled as conduct, as a record from before the portal asked always was.
 *  - The **issuer**, by Last.F among the portal's users: no match, or several, attributes it to the Admin who ran the
 *    import, noted on the row.
 *  - The **form's fields**: a scanned or flattened PDF, a Drive link and every row from the console have none read,
 *    and are stored flagged **needs review** for the issuer's chain or an Admin to fill in by hand
 *    (`counseling.review`). The source document is kept either way and is what downloads.
 *
 * **Never twice.** Each record keeps an `import_key`: the Drive file id, or the uploaded file's SHA-256. A row whose
 * key, or whose file's SHA-256, is already in the portal or earlier in the same CSV is skipped as already imported, so
 * a re-run never duplicates. The issuing unit is left empty: the import has no request to work out which of the
 * issuer's commands reached the trooper back then, so only the issuer and Admins work on the record afterwards.
 *
 * **Audited per record** (`counseling.imported`, AD-16), with the Admin who ran it as the actor and, as the reason, the
 * CSV line and whether it ran from the console. The import is Admins' only, checked here whatever the caller checked.
 */
class ImportCounselingRecordsHandler
{
    /** The forms a Date of Letter is written in, tried in turn: `2026-10-20`, `20 Oct 2026`, `20OCT2026`, `Oct 20, 2026`… */
    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d', 'j M Y', 'jMY', 'j-M-Y', 'j F Y', 'M j, Y', 'F j, Y', 'M j Y', 'F j Y'];

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly UserRepository $users,
        private readonly CounselingRecordRepository $records,
        private readonly CounselingDocuments $documents,
        private readonly AuditEnvelope $audit,
        private readonly DriveDownloadApi $drive,
    ) {}

    /**
     * @throws AuthorizationException the actor is not an Admin
     */
    public function handle(User $actor, ImportCounselingRecords $command): ImportReport
    {
        if (! $this->scope->isAdmin($actor)) {
            throw new AuthorizationException('Only an Admin may import counseling records.');
        }

        $rows = $this->plan($actor, $command);

        if (! $command->dryRun) {
            foreach ($rows as $row) {
                if ($row->isStored()) {
                    $this->store($actor, $row, $command->viaConsole);
                }
            }
        }

        return new ImportReport($rows, $command->dryRun);
    }

    /**
     * Every row resolved: what it would become, with everything the import needs to store it.
     *
     * @return list<ImportRowResult>
     */
    private function plan(User $actor, ImportCounselingRecords $command): array
    {
        $fieldsByRow = [];
        $trooperNames = [];
        $issuerNames = [];

        foreach ($command->rows as $row) {
            $fields = $row->file !== '' ? ($command->fields[self::fileKey($row->file)] ?? null) : null;
            $fieldsByRow[$row->line] = $fields;
            $trooperNames[] = self::lastF($row->trooper !== '' ? $row->trooper : ($fields->trooper ?? ''));
            $issuerNames[] = self::lastF($row->issuer !== '' ? $row->issuer : ($fields->issuer ?? ''));
        }

        // One query each for every name in the CSV.
        $troopers = $this->troopers->idsByUsername(array_values(array_filter($trooperNames)));
        $issuers = $this->users->idsByUsername(array_values(array_filter($issuerNames)));
        $seen = [];

        return array_map(function (ImportRow $row) use ($actor, $command, $fieldsByRow, $troopers, $issuers, &$seen) {
            $result = $this->resolve($actor, $command, $row, $fieldsByRow[$row->line], $troopers, $issuers);

            if (! $result->isSkipped()) {
                $this->attachDocument($command, $row, $result, $seen);
            }

            return $result;
        }, $command->rows);
    }

    /**
     * Everything about the row but its document: the source, the type, kind and date, the trooper and the issuer, and
     * the form's fields.
     *
     * @param  array<string, list<int>>  $troopers
     * @param  array<string, list<int>>  $issuers
     */
    private function resolve(User $actor, ImportCounselingRecords $command, ImportRow $row, ?ImportFields $fields, array $troopers, array $issuers): ImportRowResult
    {
        $drive = $row->driveUrl !== '';
        $result = new ImportRowResult(
            line: $row->line,
            source: $drive ? 'drive' : ($row->file !== '' ? 'file' : ''),
            label: $drive ? $row->driveUrl : CounselingRules::displayName($row->file !== '' ? $row->file : 'document'),
        );

        if ($drive === ($row->file !== '')) {
            return $result->skip($drive ? 'give either a drive_url or a file, not both' : 'give a drive_url or a file');
        }

        if ($drive) {
            try {
                $result->importKey = $this->drive->fileIdFrom($row->driveUrl);
            } catch (DriveDownloadFailed $e) {
                return $result->skip($e->getMessage());
            }
        } elseif (! isset($command->files[self::fileKey($row->file)])) {
            return $result->skip("{$row->file} was not uploaded");
        }

        // The type: the CSV's, else the form's.
        if ($row->type !== '') {
            $result->type = CounselingType::tryFrom(strtolower($row->type));
            if ($result->type === null) {
                return $result->skip('the type must be DCS or LOR');
            }
        } elseif ($fields?->type !== null) {
            $result->type = $fields->type;
        } else {
            return $result->skip('the type is unknown: give DCS or LOR in the type column');
        }

        // The kind: the CSV's, else conduct, as a record from before the portal asked always was.
        if ($row->kind !== '') {
            $result->kind = CounselingKind::tryFrom(strtolower(str_replace([' ', '-'], '_', $row->kind)));
            if ($result->kind === null) {
                return $result->skip('the kind must be conduct or missed_practice');
            }
        } else {
            $result->kind = CounselingKind::Conduct;
            $result->notes[] = 'No kind given: imported as '.strtolower(CounselingKind::Conduct->label()).'.';
        }

        // The date: the CSV's, else the form's Date of Letter.
        $written = $row->counseledOn !== '' ? $row->counseledOn : ($fields->date ?? '');
        if ($written === '') {
            return $result->skip('no date: give it in the counseled_on column (YYYY-MM-DD)');
        }
        $date = self::parseDate($written);
        if ($date === null) {
            return $result->skip("the date \"{$written}\" could not be read: give it as YYYY-MM-DD");
        }
        try {
            $result->counseledOn = CounselingRules::checkedDate($date);
        } catch (InvalidCounselingRecordException $e) {
            return $result->skip(lcfirst(rtrim($e->getMessage(), '.')));
        }

        if (! $this->resolveTrooper($command, $row, $fields, $troopers, $result)) {
            return $result;
        }

        $this->resolveIssuer($actor, $row, $fields, $issuers, $result);
        $this->resolveForm($command, $row, $fields, $result);

        return $result;
    }

    /**
     * The trooper by Last.F. False when the row is skipped. Several matches leave the row for the Admin to pick, or
     * take the pick they made, which must be one of them.
     *
     * @param  array<string, list<int>>  $troopers
     */
    private function resolveTrooper(ImportCounselingRecords $command, ImportRow $row, ?ImportFields $fields, array $troopers, ImportRowResult $result): bool
    {
        $name = self::lastF($row->trooper !== '' ? $row->trooper : ($fields->trooper ?? ''));

        if ($name === '') {
            $result->skip('no trooper: give their Last.F in the trooper column');

            return false;
        }

        $ids = $troopers[mb_strtolower($name)] ?? [];

        if ($ids === []) {
            $result->skip("no trooper named {$name} on the roster");

            return false;
        }

        $picked = $command->picks[$row->line] ?? null;
        $id = count($ids) === 1 ? $ids[0] : (in_array($picked, $ids, true) ? $picked : null);

        if ($id !== null) {
            $trooper = $this->troopers->find($id);
            $result->trooperId = $id;
            $result->trooperName = $trooper?->display_name ?? $name;

            return true;
        }

        $count = count($ids);

        if ($command->viaConsole || ! $command->dryRun) {
            $result->skip("{$count} troopers are named {$name}".($command->viaConsole ? '' : ' and none was picked'));

            return false;
        }

        $result->outcome = ImportRowResult::REVIEW;
        $result->reason = "{$count} troopers are named {$name}: pick one";
        $result->trooperName = $name;
        $result->candidates = array_map(fn (int $candidate) => [
            'id' => $candidate,
            'name' => $this->troopers->find($candidate)?->display_name ?? "#{$candidate}",
        ], $ids);

        return true;
    }

    /**
     * The issuer by Last.F among the portal's users, or the Admin running the import.
     *
     * @param  array<string, list<int>>  $issuers
     */
    private function resolveIssuer(User $actor, ImportRow $row, ?ImportFields $fields, array $issuers, ImportRowResult $result): void
    {
        $name = self::lastF($row->issuer !== '' ? $row->issuer : ($fields->issuer ?? ''));
        $ids = $name === '' ? [] : ($issuers[mb_strtolower($name)] ?? []);

        if (count($ids) === 1) {
            $result->issuerId = $ids[0];
            $result->issuerName = $this->users->find($ids[0])->username ?? $name;

            return;
        }

        $result->issuerId = $actor->id;
        $result->issuerName = $actor->username;
        $result->notes[] = match (true) {
            $name === '' => 'No issuer named: attributed to you.',
            $ids === [] => "No portal user is named {$name}: attributed to you.",
            default => count($ids)." portal users are named {$name}: attributed to you.",
        };
    }

    /** The form's fields, or a review flag saying why there are none. */
    private function resolveForm(ImportCounselingRecords $command, ImportRow $row, ?ImportFields $fields, ImportRowResult $result): void
    {
        $why = match (true) {
            $command->viaConsole => 'The console does not read the form\'s fields',
            $row->driveUrl !== '' => 'The form\'s fields are not read from a Drive link',
            $fields === null => 'No form fields could be read (a scanned or flattened PDF)',
            default => null,
        };

        if ($why === null) {
            $form = CounselingForm::fromInput($fields->form, $result->type);
            $response = CounselingResponse::fromInput($fields->form, $result->type);

            try {
                $form->check();
                // Only the length caps: an older form's appeal choice may be blank, which is not for the import to refuse.
                $response->check(CounselingType::Dcs);
                $result->formData = [...$form->toArray(), ...$response->toArray()];
            } catch (InvalidCounselingRecordException) {
                $why = 'A field in the form is longer than the portal allows';
            }

            // Nothing the leader writes in Section 2: an empty form, not worth storing as filled in.
            if ($why === null && $form->infraction === '' && $form->regulation === '' && $form->discussion === '' && $form->planAuthority === '') {
                $why = 'The form\'s fields are empty';
            }
        }

        if ($why !== null) {
            $result->formData = null;
            if ($result->outcome === ImportRowResult::IMPORT) {
                $result->outcome = ImportRowResult::REVIEW;
                $result->reason = "{$why}: fill them in after the import.";
            } else {
                $result->notes[] = "{$why}: fill them in after the import.";
            }
        }
    }

    /**
     * The row's PDF on this server, checked, with the key that keeps it from being imported twice. A Drive file is
     * downloaded once into the download directory and reused from there.
     *
     * @param  array<string, int>  $seen  the keys and SHA-256s earlier rows took, => their line
     */
    private function attachDocument(ImportCounselingRecords $command, ImportRow $row, ImportRowResult $result, array &$seen): void
    {
        if ($row->driveUrl !== '') {
            if ($this->alreadyTaken([$result->importKey], $seen, $result)) {
                return;
            }

            $path = rtrim($command->downloadDir, '/').'/drive-'.$result->importKey.'.pdf';

            if (! is_file($path)) {
                try {
                    $bytes = $this->drive->download($result->importKey, CounselingRules::DOCUMENT_MAX_BYTES);
                } catch (DriveDownloadFailed $e) {
                    $result->skip($e->getMessage());

                    return;
                }

                if (! is_dir($command->downloadDir)) {
                    @mkdir($command->downloadDir, 0700, true);
                }

                if (@file_put_contents($path, $bytes) === false) {
                    $result->skip('the downloaded file could not be kept on the server');

                    return;
                }
            }

            $result->documentName = CounselingRules::displayName("drive-{$result->importKey}.pdf");
        } else {
            $path = $command->files[self::fileKey($row->file)];
            $result->documentName = CounselingRules::displayName($row->file);
        }

        try {
            CounselingRules::checkDocument($path);
        } catch (InvalidCounselingRecordException $e) {
            $result->skip(lcfirst(rtrim($e->getMessage(), '.')));

            return;
        }

        $sha256 = (string) hash_file('sha256', $path);
        $result->importKey ??= $sha256;
        $result->path = $path;

        if ($this->alreadyTaken(array_values(array_unique([$result->importKey, $sha256])), $seen, $result)) {
            return;
        }

        $seen[$result->importKey] = $row->line;
        $seen[$sha256] = $row->line;
    }

    /**
     * Whether any of `$keys` is already in the portal or was taken by an earlier row; the row is then skipped.
     *
     * @param  list<string>  $keys
     * @param  array<string, int>  $seen
     */
    private function alreadyTaken(array $keys, array $seen, ImportRowResult $result): bool
    {
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                $result->skip("the same document as line {$seen[$key]}");

                return true;
            }
        }

        if ($this->records->importedKeys($keys) !== []) {
            $result->skip('already imported');

            return true;
        }

        return false;
    }

    /**
     * Store one row: its PDF onto the private disk as the record's source document, then the record, shared and
     * acknowledged on its date. A record that cannot be stored (its key taken by an import running at the same time,
     * say) leaves no file behind and turns the row into a skipped one.
     */
    private function store(User $actor, ImportRowResult $row, bool $viaConsole): void
    {
        try {
            $document = $this->documents->store((string) $row->path, (string) $row->documentName);
        } catch (Throwable $e) {
            Log::warning('Counseling import: a document could not be stored.', ['line' => $row->line, 'exception' => $e->getMessage()]);
            $row->skip('the document could not be stored');

            return;
        }

        try {
            $record = $this->audit->record(
                actorId: $actor->id,
                action: 'counseling.imported',
                reason: "Counseling import, CSV line {$row->line}".($viaConsole ? ', from the console' : ''),
                reasonRequired: false,
                mutation: fn () => $this->records->createImported(
                    trooperId: (int) $row->trooperId,
                    type: $row->type,
                    kind: $row->kind,
                    counseledOn: $row->counseledOn,
                    issuedBy: (int) $row->issuerId,
                    issuerUnitId: null,
                    document: $document,
                    importKey: (string) $row->importKey,
                    needsReview: $row->outcome === ImportRowResult::REVIEW,
                    formData: $row->formData,
                ),
            );
            $row->recordId = $record->id;
        } catch (Throwable $e) {
            $this->documents->delete($document->path);
            Log::warning('Counseling import: a record could not be stored.', ['line' => $row->line, 'exception' => $e->getMessage()]);
            $row->skip($this->records->importedKeys([(string) $row->importKey]) !== [] ? 'already imported' : 'the record could not be stored');
        }
    }

    /** The key a `file` cell and an uploaded file's name are matched on: the base name, lower-cased. */
    public static function fileKey(string $name): string
    {
        return mb_strtolower(basename(str_replace('\\', '/', trim($name))));
    }

    /** A Last.F as the forms and the CSV write it, with any rank in front dropped: `PFC Okafor.D` is `Okafor.D`. */
    private static function lastF(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return (string) end($parts);
    }

    /** The date as written, in one of {@see DATE_FORMATS}, or null. */
    private static function parseDate(string $written): ?CarbonImmutable
    {
        $written = trim($written);

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $written, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }
}
