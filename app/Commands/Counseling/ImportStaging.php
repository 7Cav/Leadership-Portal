<?php

namespace App\Commands\Counseling;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a counseling import (#565) waits between the Admin's check and the queued import: one directory per batch, a
 * UUID, under `counseling-import/` on the private `local` disk (`storage/app/private`, served only through a signed
 * URL, which nothing makes for these). Never on the `counseling` disk: that holds the source documents, behind
 * `CounselingDocuments` alone, and a staged file is not one until the import stores it.
 *
 * A batch holds the CSV as uploaded, each uploaded PDF under a name made here (`files/{n}.pdf`; the Admin's file name
 * is only a key in the manifest, never part of a path), the fields the browser read, the Drive downloads the check
 * fetched (`drive/`) so the import does not fetch them again, and the check's result. It is deleted when the import
 * finishes; a batch nobody imported is deleted {@see STALE_HOURS} hours on, the next time anyone checks a CSV.
 */
class ImportStaging
{
    public const DISK = 'local';

    public const ROOT = 'counseling-import';

    /** How long an unimported batch is kept. */
    public const STALE_HOURS = 24;

    /**
     * A new batch: the CSV, the uploaded PDFs (`client name => temporary path`) and the fields read from them, by
     * client name. Answers its id.
     *
     * @param  array<string, string>  $uploads
     * @param  array<string, mixed>  $fields
     */
    public function create(int $actorId, string $csvName, string $csv, array $uploads, array $fields): string
    {
        $this->prune();

        $batch = Str::uuid()->toString();
        $dir = self::ROOT."/{$batch}";
        $files = [];
        $n = 0;

        $this->disk()->put("{$dir}/import.csv", $csv);

        foreach ($uploads as $name => $path) {
            $stored = 'files/'.(++$n).'.pdf';
            $this->disk()->put("{$dir}/{$stored}", (string) file_get_contents($path));
            $files[ImportCounselingRecordsHandler::fileKey((string) $name)] = $stored;
        }

        $read = [];
        foreach ($fields as $name => $value) {
            $read[ImportCounselingRecordsHandler::fileKey((string) $name)] = $value;
        }

        $this->disk()->put("{$dir}/manifest.json", (string) json_encode([
            'actor_id' => $actorId,
            'csv_name' => mb_substr(basename(str_replace('\\', '/', $csvName)), 0, 150),
            'created_at' => now()->toIso8601String(),
            'files' => $files,
            'fields' => $read,
        ]));

        return $batch;
    }

    /** An empty batch for a console run: only its download directory is used. Answers its id. */
    public function scratch(): string
    {
        $batch = Str::uuid()->toString();
        $this->disk()->makeDirectory(self::ROOT."/{$batch}/drive");

        return $batch;
    }

    /**
     * The batch's manifest, or null when it is gone (or `$batch` is no batch id).
     *
     * @return array{actor_id: int, csv_name: string, created_at: string, files: array<string, string>, fields: array<string, mixed>}|null
     */
    public function manifest(string $batch): ?array
    {
        if (! Str::isUuid($batch) || ! $this->disk()->exists($this->dir($batch).'/manifest.json')) {
            return null;
        }

        $manifest = json_decode((string) $this->disk()->get($this->dir($batch).'/manifest.json'), true);

        return is_array($manifest) ? $manifest : null;
    }

    public function csv(string $batch): string
    {
        return (string) $this->disk()->get($this->dir($batch).'/import.csv');
    }

    /**
     * The uploaded PDFs, as lower-cased client name => absolute path.
     *
     * @return array<string, string>
     */
    public function files(string $batch): array
    {
        return array_map(fn (string $stored) => $this->disk()->path($this->dir($batch)."/{$stored}"), $this->manifest($batch)['files'] ?? []);
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

    /** The absolute directory the batch's Drive downloads are kept in. */
    public function downloadDir(string $batch): string
    {
        $this->disk()->makeDirectory($this->dir($batch).'/drive');

        return $this->disk()->path($this->dir($batch).'/drive');
    }

    /**
     * Keep the check's result beside the batch, for the page to show.
     *
     * @param  array<string, mixed>  $plan
     */
    public function savePlan(string $batch, array $plan): void
    {
        $this->disk()->put($this->dir($batch).'/plan.json', (string) json_encode($plan));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(string $batch): ?array
    {
        if ($this->manifest($batch) === null || ! $this->disk()->exists($this->dir($batch).'/plan.json')) {
            return null;
        }

        $plan = json_decode((string) $this->disk()->get($this->dir($batch).'/plan.json'), true);

        return is_array($plan) ? $plan : null;
    }

    public function delete(string $batch): void
    {
        if (Str::isUuid($batch)) {
            $this->disk()->deleteDirectory($this->dir($batch));
        }
    }

    /** Delete every batch older than {@see STALE_HOURS} hours. */
    public function prune(): void
    {
        $cutoff = now()->subHours(self::STALE_HOURS)->getTimestamp();

        foreach ($this->disk()->directories(self::ROOT) as $dir) {
            $batch = basename($dir);
            // When the batch directory was made: a console run's scratch batch has no manifest.
            $stamp = @filemtime($this->disk()->path($dir));

            if (Str::isUuid($batch) && $stamp !== false && $stamp < $cutoff) {
                $this->disk()->deleteDirectory($dir);
            }
        }
    }

    private function dir(string $batch): string
    {
        return self::ROOT.'/'.(Str::isUuid($batch) ? $batch : throw new \InvalidArgumentException('Not an import batch.'));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
