<?php

namespace App\Repositories\TemplateVersionRepository;

use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitTemplateChoice;
use App\Models\UnitType;
use App\Repositories\TemplateNameTakenException;
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

    /** What a company's template is called when it is made for it by {@see author()}. */
    private const COMPANY_TEMPLATE_NAME = 'Company template';

    public function createTemplate(int $companyId, ArtifactType $type, string $name, string $body, int $createdBy): Template
    {
        return DB::transaction(function () use ($companyId, $type, $name, $body, $createdBy) {
            $this->lockCompany($companyId);

            // Decided under the lock, so two quick submits of one name cannot both pass the Command's own check.
            $taken = Template::query()
                ->where('company_id', $companyId)
                ->where('artifact_type', $type->value)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($taken) {
                throw new TemplateNameTakenException($name);
            }

            return $this->newTemplate($companyId, $type, $name, $body, $createdBy);
        });
    }

    public function authorVersion(Template $template, string $body, int $createdBy): TemplateVersion
    {
        return $this->retrying(fn () => DB::transaction(function () use ($template, $body, $createdBy) {
            $stored = $this->lockTemplate($template);

            return $this->appendVersion($stored, $body, $createdBy);
        }));
    }

    public function author(int $companyId, ArtifactType $type, string $body, int $createdBy): TemplateVersion
    {
        return $this->retrying(fn () => DB::transaction(function () use ($companyId, $type, $body, $createdBy) {
            $this->lockCompany($companyId);

            $choice = $this->choiceFor($companyId, $type);

            if ($choice === null) {
                return $this->newTemplate($companyId, $type, self::COMPANY_TEMPLATE_NAME, $body, $createdBy)->activeVersion;
            }

            return $this->appendVersion(Template::query()->whereKey($choice->template_id)->lockForUpdate()->firstOrFail(), $body, $createdBy);
        }));
    }

    public function activate(TemplateVersion $version, int $activatedBy): TemplateVersion
    {
        return DB::transaction(function () use ($version) {
            // A locking read, so a version cannot be activated from a stale copy of itself.
            $stored = TemplateVersion::query()->whereKey($version->id)->lockForUpdate()->first()
                ?? throw new InvalidArgumentException("Template version {$version->id} does not exist.");

            $template = $this->lockTemplate(Template::query()->findOrFail($stored->template_id));

            // Decided under the lock, so a double-click cannot record two activations.
            if ($template->active_version_id === $stored->id) {
                throw new TemplateUnchangedException($stored);
            }

            $this->point($template, $stored);

            return $stored;
        });
    }

    public function setEnabled(Template $template, bool $enabled): Template
    {
        return DB::transaction(function () use ($template, $enabled) {
            $stored = $this->lockTemplate($template);

            if ($stored->enabled === $enabled) {
                throw TemplateUnchangedException::forTemplate($stored);
            }

            $stored->forceFill(['enabled' => $enabled])->save();

            return $stored;
        });
    }

    public function choose(Unit $unit, Template $template, int $chosenBy): UnitTemplateChoice
    {
        return DB::transaction(function () use ($unit, $template, $chosenBy) {
            Unit::query()->whereKey($unit->id)->lockForUpdate()->first(['id']);

            $choice = UnitTemplateChoice::query()
                ->where('unit_id', $unit->id)
                ->where('artifact_type', $template->artifact_type->value)
                ->first();

            if ($choice?->template_id === $template->id) {
                throw TemplateUnchangedException::forChoice($choice);
            }

            $choice ??= new UnitTemplateChoice(['unit_id' => $unit->id, 'artifact_type' => $template->artifact_type]);
            $choice->fill(['template_id' => $template->id, 'chosen_by' => $chosenBy])->save();

            return $choice;
        });
    }

    public function clearChoice(Unit $unit, ArtifactType $type): UnitTemplateChoice
    {
        return DB::transaction(function () use ($unit, $type) {
            $choice = UnitTemplateChoice::query()
                ->where('unit_id', $unit->id)
                ->where('artifact_type', $type->value)
                ->lockForUpdate()
                ->first();

            if ($choice === null) {
                throw TemplateUnchangedException::noChoice();
            }

            $choice->delete();

            return $choice;
        });
    }

    public function choiceFor(int $unitId, ArtifactType $type): ?UnitTemplateChoice
    {
        return UnitTemplateChoice::query()
            ->with('template.activeVersion')
            ->where('unit_id', $unitId)
            ->where('artifact_type', $type->value)
            ->first();
    }

    public function activeFor(int $companyId, ArtifactType $type): ?TemplateVersion
    {
        $choice = $this->choiceFor($companyId, $type);

        // Only one of the company's own templates is its active one; a choice naming anything else is not.
        return $choice?->template?->company_id === $companyId ? $choice->template->activeVersion : null;
    }

    public function historyOf(Template $template): Collection
    {
        return TemplateVersion::query()
            ->with('author')
            ->where('template_id', $template->id)
            ->orderByDesc('version')
            ->get();
    }

    public function history(int $companyId, ArtifactType $type): Collection
    {
        $choice = $this->choiceFor($companyId, $type);

        return $choice?->template?->company_id === $companyId ? $this->historyOf($choice->template) : collect();
    }

    public function templatesOf(int $companyId, ArtifactType $type): Collection
    {
        return Template::query()
            ->with('activeVersion')
            ->where('company_id', $companyId)
            ->where('artifact_type', $type->value)
            ->orderBy('id')
            ->get();
    }

    public function regimentDefault(ArtifactType $type): ?Template
    {
        return Template::query()
            ->with('activeVersion')
            ->whereNull('company_id')
            ->where('artifact_type', $type->value)
            ->orderBy('id')
            ->first();
    }

    public function findTemplate(int $id): ?Template
    {
        return Template::query()->with('activeVersion')->find($id);
    }

    public function find(int $id): ?TemplateVersion
    {
        return TemplateVersion::query()->find($id);
    }

    public function hasTemplatesFor(Unit $company): bool
    {
        return Template::query()->where('company_id', $company->getKey())->exists();
    }

    /**
     * A new template with version 1 active, made the company's active template when it has none. Runs inside the
     * caller's transaction, under the company lock.
     */
    private function newTemplate(int $companyId, ArtifactType $type, string $name, string $body, int $createdBy): Template
    {
        $template = new Template;
        $template->forceFill([
            'company_id' => $companyId,
            'artifact_type' => $type,
            'name' => $name,
            'enabled' => true,
            'created_by' => $createdBy,
        ])->save();

        $version = $this->insertVersion($template, 1, $body, $createdBy);
        $this->point($template, $version);

        $hasActive = UnitTemplateChoice::query()->where('unit_id', $companyId)->where('artifact_type', $type->value)->exists();

        if (! $hasActive) {
            UnitTemplateChoice::query()->create([
                'unit_id' => $companyId,
                'artifact_type' => $type,
                'template_id' => $template->id,
                'chosen_by' => $createdBy,
            ]);
        }

        return $template->setRelation('activeVersion', $version);
    }

    /**
     * The next version of a locked template, made its active one. Runs inside the caller's transaction.
     *
     * @throws TemplateUnchangedException when `$body` is the active version's body already.
     */
    private function appendVersion(Template $template, string $body, int $createdBy): TemplateVersion
    {
        // Decided under the lock, so two quick submits of the same text cannot both pass it.
        $active = $template->active_version_id === null ? null : TemplateVersion::query()->find($template->active_version_id);

        if ($active !== null && $active->body === $body) {
            throw new TemplateUnchangedException($active);
        }

        // A locking read: inside the audited transaction a plain read is a snapshot that cannot see a version a
        // racing author just committed, so a retry would pick the same number again.
        $next = 1 + (int) TemplateVersion::query()->where('template_id', $template->id)->lockForUpdate()->max('version');

        $version = $this->insertVersion($template, $next, $body, $createdBy);
        $this->point($template, $version);

        return $version;
    }

    private function insertVersion(Template $template, int $number, string $body, int $createdBy): TemplateVersion
    {
        return TemplateVersion::query()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
            'artifact_type' => $template->artifact_type,
            'version' => $number,
            'body' => $body,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Run `$write`, retrying when a racing author took the version number first. Both versions are kept: ours retries
     * with the next one.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function retrying(callable $write): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $write();
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Take the lock every write to this template goes through: its company's `units` row for a company template (so
     * all of one company's template writes run one at a time), the template row itself for the regiment default.
     * Returns the template as stored, read under the lock.
     */
    private function lockTemplate(Template $template): Template
    {
        if ($template->company_id !== null) {
            $this->lockCompany($template->company_id);
        }

        return Template::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Take the company's `units` row for update: one lock that every template write for the company goes through,
     * so its writes run one at a time. That also replaces a locking read of an empty version set, which takes a gap
     * lock that two first-ever authors can deadlock on.
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

    /** Move the template's active-version pointer to this version. */
    private function point(Template $template, TemplateVersion $version): void
    {
        $template->forceFill(['active_version_id' => $version->id])->save();
        $template->setRelation('activeVersion', $version);
    }
}
