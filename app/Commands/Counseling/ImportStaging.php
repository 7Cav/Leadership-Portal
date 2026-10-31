<?php

namespace App\Commands\Counseling;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Where a counseling import (#565) waits between the Admin's check and the queued import, and where the import's
 * outcome is kept for the page: one directory per batch, a UUID, under `counseling-import/` on the private `local` disk
 * (`storage/app/private`, served only through a signed URL, which nothing makes for these). Never on the `counseling`
 * disk: that holds the source documents, behind `CounselingDocuments` alone, and a staged file is not one until the
 * import stores it.
 *
 * A batch holds the CSV as uploaded, each uploaded PDF under a name made here (`files/{n}.pdf`; the Admin's file name
 * is only a key in the manifest, never part of a path), the fields the browser read, the Drive downloads the check
 * fetched (`drive/`) so the import does not fetch them again, the check's result (`plan.json`) and, once the Admin
 * imports, the run's status and final report (`status.json`: queued, running, finished, refused or failed, with a plain
 * reason). When the run ends its CSV and PDFs are deleted ({@see clearFiles()}); the manifest, the check and the
 * outcome stay for the page. A whole batch is deleted {@see STALE_HOURS} hours on, the next time anyone checks a CSV.
 *
 * The `local` disk does not throw on a failed write, so every write and read here is checked and a failure raises
 * {@see ImportStagingFailed}: a batch is never left half-written without anyone knowing.
 */
class ImportStaging
{
    public const DISK = 'local';

    public const ROOT = 'counseling-import';

    /** How long a batch, and the outcome of its import, is kept. */
    public const STALE_HOURS = 24;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const FINISHED = 'finished';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    /**
     * A new batch: the CSV, the uploaded PDFs (`client name => temporary path`) and the fields read from them, by
     * client name. Answers its id.
     *
     * @param  array<string, string>  $uploads
     * @param  array<string, mixed>  $fields
     *
     * @throws ImportStagingFailed
     */
    public function create(int $actorId, string $csvName, string $csv, array $uploads, array $fields): string
    {
        $this->prune();

        $batch = Str::uuid()->toString();
        $dir = self::ROOT."/{$batch}";
        $files = [];
        $n = 0;

        try {
            $this->put("{$dir}/import.csv", $csv);

            foreach ($uploads as $name => $path) {
                $bytes = is_readable($path) ? file_get_contents($path) : false;
                if ($bytes === false) {
                    throw ImportStagingFailed::read($path);
                }

                $stored = 'files/'.(++$n).'.pdf';
                $this->put("{$dir}/{$stored}", $bytes);
                $files[ImportCounselingRecordsHandler::fileKey((string) $name)] = $stored;
            }

            $read = [];
            foreach ($fields as $name => $value) {
                $read[ImportCounselingRecordsHandler::fileKey((string) $name)] = $value;
            }

            $this->putJson("{$dir}/manifest.json", [
                'actor_id' => $actorId,
                'csv_name' => mb_substr(basename(str_replace('\\', '/', $csvName)), 0, 150),
                'created_at' => now()->toIso8601String(),
                'files' => $files,
                'fields' => $read,
            ]);
        } catch (ImportStagingFailed $e) {
            // Nothing half-written stays behind.
            $this->delete($batch);

            throw $e;
        }

        return $batch;
    }

    /**
     * An empty batch for a console run: only its download directory is used. Answers its id.
     *
     * @throws ImportStagingFailed
     */
    public function scratch(): string
    {
        $batch = Str::uuid()->toString();
        $this->downloadDir($batch);

        return $batch;
    }

    /**
     * The batch's manifest, or null when it is gone (or `$batch` is no batch id).
     *
     * @return array{actor_id: int, csv_name: string, created_at: string, files: array<string, string>, fields: array<string, mixed>}|null
     *
     * @throws ImportStagingFailed the manifest is there but cannot be read
     */
    public function manifest(string $batch): ?array
    {
        return Str::isUuid($batch) ? $this->getJson($this->dir($batch).'/manifest.json') : null;
    }

    /** @throws ImportStagingFailed */
    public function csv(string $batch): string
    {
        $path = $this->dir($batch).'/import.csv';
        $csv = $this->disk()->get($path);

        return $csv ?? throw ImportStagingFailed::read($path);
    }

    /**
     * The uploaded PDFs, as lower-cased client name => absolute path. A PDF the manifest names that is not on disk is a
     * broken batch, not a file the Admin forgot to upload.
     *
     * @return array<string, string>
     *
     * @throws ImportStagingFailed
     */
    public function files(string $batch): array
    {
        return array_map(function (string $stored) use ($batch) {
            $path = $this->disk()->path($this->dir($batch)."/{$stored}");

            return is_file($path) ? $path : throw ImportStagingFailed::read($path);
        }, $this->manifest($batch)['files'] ?? []);
    }

    /**
     * What the browser read from each uploaded PDF.
     *
     * @return array<string, ImportFields|null>
     */
    public function fields(string $batch): array
    {
        return array_map(fn (mixed $read) => ImportFields::fromInput($read), $this->manifest($batch)['fields'] ?? []);
    }

    /**
     * The absolute directory the batch's Drive downloads are kept in, made when missing.
     *
     * @throws ImportStagingFailed
     */
    public function downloadDir(string $batch): string
    {
        $dir = $this->dir($batch).'/drive';

        if (! $this->disk()->exists($dir) && ! $this->disk()->makeDirectory($dir)) {
            throw ImportStagingFailed::write($dir);
        }

        return $this->disk()->path($dir);
    }

    /**
     * Keep the check's result beside the batch, for the page to show.
     *
     * @param  array<string, mixed>  $plan
     *
     * @throws ImportStagingFailed
     */
    public function savePlan(string $batch, array $plan): void
    {
        $this->putJson($this->dir($batch).'/plan.json', $plan);
    }

    /**
     * The check's result, or null when the batch or its check is gone.
     *
     * @return array<string, mixed>|null
     *
     * @throws ImportStagingFailed
     */
    public function plan(string $batch): ?array
    {
        return $this->manifest($batch) === null ? null : $this->getJson($this->dir($batch).'/plan.json');
    }

    /**
     * Record where the batch's import stands: `$state` one of the constants above, a plain `$reason` for a refused or
     * failed run, and the run's report (`ImportReport::toArray()`) once there is one.
     *
     * @param  array<string, mixed>|null  $report
     *
     * @throws ImportStagingFailed
     */
    public function saveStatus(string $batch, string $state, ?string $reason = null, ?array $report = null): void
    {
        $this->putJson($this->dir($batch).'/status.json', [
            'state' => $state,
            'reason' => $reason,
            'report' => $report,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Where the batch's import stands, or null when nobody has imported it yet.
     *
     * @return array{state: string, reason: ?string, report: ?array<string, mixed>, updatedAt: string}|null
     *
     * @throws ImportStagingFailed
     */
    public function status(string $batch): ?array
    {
        return Str::isUuid($batch) ? $this->getJson($this->dir($batch).'/status.json') : null;
    }

    /** Delete the batch's CSV, PDFs and Drive downloads once its import has run, keeping the check and the outcome. */
    public function clearFiles(string $batch): void
    {
        $dir = $this->dir($batch);
        $this->disk()->delete("{$dir}/import.csv");
        $this->disk()->deleteDirectory("{$dir}/files");
        $this->disk()->deleteDirectory("{$dir}/drive");
    }

    public function delete(string $batch): void
    {
        if (Str::isUuid($batch)) {
            $this->disk()->deleteDirectory($this->dir($batch));
        }
    }

    /** Delete every batch older than {@see STALE_HOURS} hours, outcome and all. */
    public function prune(): void
    {
        $cutoff = now()->subHours(self::STALE_HOURS)->getTimestamp();

        foreach ($this->disk()->directories(self::ROOT) as $dir) {
            $full = $this->disk()->path($dir);
            // When the batch directory was made: a console run's scratch batch has no manifest.
            $stamp = is_dir($full) ? filemtime($full) : false;

            if (Str::isUuid(basename($dir)) && $stamp !== false && $stamp < $cutoff) {
                $this->disk()->deleteDirectory($dir);
            }
        }
    }

    /** @throws ImportStagingFailed */
    private function put(string $path, string $contents): void
    {
        try {
            $written = $this->disk()->put($path, $contents);
        } catch (Throwable $e) {
            throw ImportStagingFailed::write($path, $e);
        }

        if ($written !== true) {
            throw ImportStagingFailed::write($path);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws ImportStagingFailed
     */
    private function putJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        $this->put($path, $json === false ? throw ImportStagingFailed::write($path) : $json);
    }

    /**
     * The JSON file at `$path`, or null when there is none.
     *
     * @return array<string, mixed>|null
     *
     * @throws ImportStagingFailed it is there but cannot be read or is not JSON
     */
    private function getJson(string $path): ?array
    {
        if (! $this->disk()->exists($path)) {
            return null;
        }

        $value = json_decode((string) $this->disk()->get($path), true);

        return is_array($value) ? $value : throw ImportStagingFailed::read($path);
    }

    private function dir(string $batch): string
    {
        return self::ROOT.'/'.(Str::isUuid($batch) ? $batch : throw new InvalidArgumentException('Not an import batch.'));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
