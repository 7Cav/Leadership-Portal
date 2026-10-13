<?php

namespace App\Repositories\TemplateVersionRepository;

use App\Models\ActiveTemplateVersion;
use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Eloquent implements TemplateVersionRepository
{
    /** How many times a racing author retries for the next free version number before giving up. */
    private const ATTEMPTS = 5;

    public function author(int $companyId, ArtifactType $type, string $body, int $createdBy): TemplateVersion
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($companyId, $type, $body, $createdBy) {
                    $this->lockCompany($companyId);

                    // Decided under the lock, so two quick submits of the same text cannot both pass it.
                    $active = $this->activeFor($companyId, $type);

                    if ($active !== null && $active->body === $body) {
                        throw new TemplateUnchangedException($active);
                    }

                    $version = TemplateVersion::query()->create([
                        'company_id' => $companyId,
                        'artifact_type' => $type,
                        // A locking read: inside the audited transaction a plain read is a snapshot that cannot
                        // see a version a racing author just committed, so a retry would pick the same number again.
                        'version' => 1 + (int) TemplateVersion::query()
                            ->where('company_id', $companyId)
                            ->where('artifact_type', $type->value)
                            ->lockForUpdate()
                            ->max('version'),
                        'body' => $body,
                        'created_by' => $createdBy,
                    ]);

                    $this->point($version, $createdBy);

                    return $version;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Another author took this number between our read and our insert. Both versions are
                // kept: ours retries with the next one.
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    public function activate(TemplateVersion $version, int $activatedBy): TemplateVersion
    {
        return DB::transaction(function () use ($version, $activatedBy) {
            $this->lockCompany($version->company_id);

            // A locking read, so a version cannot be activated from a stale copy of itself.
            $stored = TemplateVersion::query()->whereKey($version->id)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException("Template version {$version->id} does not exist.");

            // Decided under the lock, so a double-click cannot record two activations.
            if ($this->activeFor($stored->company_id, $stored->artifact_type)?->id === $stored->id) {
                throw new TemplateUnchangedException($stored);
            }

            $this->point($stored, $activatedBy);

            return $stored;
        });
    }

    public function activeFor(int $companyId, ArtifactType $type): ?TemplateVersion
    {
        return TemplateVersion::query()
            ->whereKey(
                ActiveTemplateVersion::query()
                    ->select('template_version_id')
                    ->where('company_id', $companyId)
                    ->where('artifact_type', $type->value),
            )
            ->first();
    }

    public function history(int $companyId, ArtifactType $type): Collection
    {
        return TemplateVersion::query()
            ->with('author')
            ->where('company_id', $companyId)
            ->where('artifact_type', $type->value)
            ->orderByDesc('version')
            ->get();
    }

    public function find(int $id): ?TemplateVersion
    {
        return TemplateVersion::query()->find($id);
    }

    public function hasTemplatesFor(Unit $company): bool
    {
        return TemplateVersion::query()->where('company_id', $company->getKey())->exists();
    }

    /**
     * Take the company's `units` row for update: one lock that every template write for the company goes through,
     * so authors and activations for it run one at a time. That also replaces a locking read of an empty version
     * set, which takes a gap lock that two first-ever authors can deadlock on.
     *
     * @throws InvalidArgumentException when `$companyId` is not a `units` row of type company.
     */
    private function lockCompany(int $companyId): void
    {
        $company = Unit::query()->whereKey($companyId)->where('type', UnitType::Company->value)->lockForUpdate()->first(['id']);

        if ($company === null) {
            throw new InvalidArgumentException("Unit {$companyId} is not a company; templates belong to a company (AD-17).");
        }
    }

    /**
     * Move the (company, artifact type) pointer to this version. An upsert on the pointer's primary key, so
     * the pointer is one row that moves and never becomes two.
     */
    private function point(TemplateVersion $version, int $activatedBy): void
    {
        $now = now();

        ActiveTemplateVersion::query()->upsert(
            [[
                'company_id' => $version->company_id,
                'artifact_type' => $version->artifact_type->value,
                'template_version_id' => $version->id,
                'activated_by' => $activatedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['company_id', 'artifact_type'],
            ['template_version_id', 'activated_by', 'updated_at'],
        );
    }
}
