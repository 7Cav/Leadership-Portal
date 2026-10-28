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
use Illuminate\Database\Eloquent\Builder;
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

    public function createTemplate(int $ownerId, ArtifactType $type, string $name, string $body, int $createdBy, ?int $createdByAdmin = null, ?int $createdAsUnit = null): Template
    {
        $who = [$createdBy, $createdByAdmin, $createdAsUnit];

        return DB::transaction(function () use ($ownerId, $type, $name, $body, $who) {
            $owner = $this->lockOwner($ownerId);

            // Decided under the lock, so two quick submits of one name cannot both pass the Command's own check.
            $taken = Template::query()
                ->where($this->ownerColumn($owner), $ownerId)
                ->where('artifact_type', $type->value)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($taken) {
                throw new TemplateNameTakenException($name);
            }

            return $this->newTemplate($owner, $type, $name, $body, $who);
        });
    }

    public function authorVersion(Template $template, string $body, int $createdBy, ?int $createdByAdmin = null, ?int $createdAsUnit = null): TemplateVersion
    {
        $who = [$createdBy, $createdByAdmin, $createdAsUnit];

        return $this->retrying(fn () => DB::transaction(function () use ($template, $body, $who) {
            $stored = $this->lockTemplate($template);

            return $this->appendVersion($stored, $body, $who);
        }));
    }

    public function author(int $companyId, ArtifactType $type, string $body, int $createdBy, ?int $createdByAdmin = null, ?int $createdAsUnit = null): TemplateVersion
    {
        $who = [$createdBy, $createdByAdmin, $createdAsUnit];

        return $this->retrying(fn () => DB::transaction(function () use ($companyId, $type, $body, $who) {
            $company = $this->lockCompany($companyId);

            $choice = $this->choiceFor($companyId, $type);

            if ($choice === null) {
                return $this->newTemplate($company, $type, self::COMPANY_TEMPLATE_NAME, $body, $who)->activeVersion;
            }

            return $this->appendVersion(Template::query()->whereKey($choice->template_id)->lockForUpdate()->firstOrFail(), $body, $who);
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

    public function choose(Unit $unit, Template $template, int $chosenBy, ?int $chosenByAdmin = null, ?int $chosenAsUnit = null): UnitTemplateChoice
    {
        return DB::transaction(function () use ($unit, $template, $chosenBy, $chosenByAdmin, $chosenAsUnit) {
            Unit::query()->whereKey($unit->id)->lockForUpdate()->first(['id']);

            $choice = UnitTemplateChoice::query()
                ->where('unit_id', $unit->id)
                ->where('artifact_type', $template->artifact_type->value)
                ->first();

            if ($choice?->template_id === $template->id) {
                throw TemplateUnchangedException::forChoice($choice);
            }

            $choice ??= new UnitTemplateChoice(['unit_id' => $unit->id, 'artifact_type' => $template->artifact_type]);
            $choice->fill([
                'template_id' => $template->id,
                'chosen_by' => $chosenBy,
                'chosen_by_admin' => $chosenByAdmin,
                'chosen_as_unit' => $chosenAsUnit,
            ])->save();

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
            ->with(['author', 'authorAdmin', 'authorUnit'])
            ->where('template_id', $template->id)
            ->orderByDesc('version')
            ->get();
    }

    public function history(int $companyId, ArtifactType $type): Collection
    {
        $choice = $this->choiceFor($companyId, $type);

        return $choice?->template?->company_id === $companyId ? $this->historyOf($choice->template) : collect();
    }

    public function templatesOf(int $ownerId, ArtifactType $type): Collection
    {
        return Template::query()
            ->with('activeVersion')
            ->where(fn ($query) => $this->ownedBy($query, $ownerId))
            ->where('artifact_type', $type->value)
            ->orderBy('id')
            ->get();
    }

    public function regimentDefault(ArtifactType $type): ?Template
    {
        return Template::query()
            ->with('activeVersion')
            ->whereNull('company_id')
            ->whereNull('section_id')
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

    public function hasTemplatesFor(Unit $unit): bool
    {
        return Template::query()->where(fn ($query) => $this->ownedBy($query, $unit->getKey()))->exists();
    }

    public function copyTemplate(Template $source, int $companyId, string $name, bool $enabled, int $copiedBy, ?int $copiedByAdmin = null, ?int $copiedAsUnit = null): Template
    {
        return DB::transaction(function () use ($source, $companyId, $name, $enabled, $copiedBy, $copiedByAdmin, $copiedAsUnit) {
            $this->lockCompany($companyId);

            // Read from the stored row, so a stale model cannot copy a version that is no longer the active one.
            $pointer = Template::query()->whereKey($source->id)->value('active_version_id');
            $active = $pointer === null ? null : TemplateVersion::query()->find($pointer);

            if ($active === null) {
                throw new InvalidArgumentException("Template {$source->id} has no active version to copy.");
            }

            $copy = new Template;
            $copy->forceFill([
                'company_id' => $companyId,
                'artifact_type' => $source->artifact_type,
                // Decided under the lock, so two copies made at once cannot take one name.
                'name' => $this->freeName('company_id', $companyId, $source->artifact_type, $name),
                'enabled' => $enabled,
                'created_by' => $copiedBy,
                'created_by_admin' => $copiedByAdmin,
                'created_as_unit' => $copiedAsUnit,
                'copied_from_id' => $source->id,
            ])->save();

            // The text and its author are the source's; only the copy itself is the copier's.
            $version = $this->insertVersion($copy, 1, $active->body, [$active->created_by, $active->created_by_admin, $active->created_as_unit]);
            $this->point($copy, $version);

            return $copy;
        });
    }

    /**
     * A new template with version 1 active, owned by the company or the section. A company's is made its active
     * template when it has none; a section's waits for its leader's pick. Runs inside the caller's transaction, under
     * the owner's lock.
     *
     * @param  array{0: int, 1: ?int, 2: ?int}  $who  who wrote it, the Admin acting as them and the billet's unit
     */
    private function newTemplate(Unit $owner, ArtifactType $type, string $name, string $body, array $who): Template
    {
        [$createdBy, $createdByAdmin, $createdAsUnit] = $who;

        $template = new Template;
        $template->forceFill([
            $this->ownerColumn($owner) => $owner->id,
            'artifact_type' => $type,
            'name' => $name,
            'enabled' => true,
            'created_by' => $createdBy,
            'created_by_admin' => $createdByAdmin,
            'created_as_unit' => $createdAsUnit,
        ])->save();

        $version = $this->insertVersion($template, 1, $body, $who);
        $this->point($template, $version);

        if ($owner->type !== UnitType::Company) {
            return $template->setRelation('activeVersion', $version);
        }

        $hasActive = UnitTemplateChoice::query()->where('unit_id', $owner->id)->where('artifact_type', $type->value)->exists();

        if (! $hasActive) {
            UnitTemplateChoice::query()->create([
                'unit_id' => $owner->id,
                'artifact_type' => $type,
                'template_id' => $template->id,
                'chosen_by' => $createdBy,
                'chosen_by_admin' => $createdByAdmin,
                'chosen_as_unit' => $createdAsUnit,
            ]);
        }

        return $template->setRelation('activeVersion', $version);
    }

    /** `$name`, or the first of `$name (2)`, `$name (3)`... that the owner does not hold yet (case aside). */
    private function freeName(string $column, int $ownerId, ArtifactType $type, string $name): string
    {
        $taken = Template::query()
            ->where($column, $ownerId)
            ->where('artifact_type', $type->value)
            ->pluck('name')
            ->map(fn (string $held) => mb_strtolower($held))
            ->all();

        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $name : "{$name} ({$n})";

            if (! in_array(mb_strtolower($candidate), $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * The next version of a locked template, made its active one. Runs inside the caller's transaction.
     *
     * @param  array{0: int, 1: ?int, 2: ?int}  $who  as for {@see newTemplate()}
     *
     * @throws TemplateUnchangedException when `$body` is the active version's body already.
     */
    private function appendVersion(Template $template, string $body, array $who): TemplateVersion
    {
        // Decided under the lock, so two quick submits of the same text cannot both pass it.
        $active = $template->active_version_id === null ? null : TemplateVersion::query()->find($template->active_version_id);

        if ($active !== null && $active->body === $body) {
            throw new TemplateUnchangedException($active);
        }

        // A locking read: inside the audited transaction a plain read is a snapshot that cannot see a version a
        // racing author just committed, so a retry would pick the same number again.
        $next = 1 + (int) TemplateVersion::query()->where('template_id', $template->id)->lockForUpdate()->max('version');

        $version = $this->insertVersion($template, $next, $body, $who);
        $this->point($template, $version);

        return $version;
    }

    /**
     * @param  array{0: ?int, 1: ?int, 2: ?int}  $who  as for {@see newTemplate()}; a copy keeps its source's, null for the seeded default
     */
    private function insertVersion(Template $template, int $number, string $body, array $who): TemplateVersion
    {
        [$createdBy, $createdByAdmin, $createdAsUnit] = $who;

        return TemplateVersion::query()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
            'section_id' => $template->section_id,
            'artifact_type' => $template->artifact_type,
            'version' => $number,
            'body' => $body,
            'created_by' => $createdBy,
            'created_by_admin' => $createdByAdmin,
            'created_as_unit' => $createdAsUnit,
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
     * Take the lock every write to this template goes through: its owner's `units` row for a company's or a section's
     * template (so all of one unit's template writes run one at a time), the template row itself for the regiment
     * default. Returns the template as stored, read under the lock.
     */
    private function lockTemplate(Template $template): Template
    {
        if ($template->ownerId() !== null) {
            $this->lockOwner($template->ownerId());
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
    private function lockCompany(int $companyId): Unit
    {
        $company = Unit::query()->whereKey($companyId)->where('type', UnitType::Company->value)->lockForUpdate()->first(['id', 'type']);

        if ($company === null) {
            throw new InvalidArgumentException("Unit {$companyId} is not a company; templates belong to a company (AD-17).");
        }

        return $company;
    }

    /**
     * As {@see lockCompany()}, for the unit that owns a template: a company, or a section for its own.
     *
     * @throws InvalidArgumentException when `$ownerId` is not a `units` row of type company or section.
     */
    private function lockOwner(int $ownerId): Unit
    {
        $owner = Unit::query()->whereKey($ownerId)->whereIn('type', [UnitType::Company->value, UnitType::Section->value])->lockForUpdate()->first(['id', 'type']);

        if ($owner === null) {
            throw new InvalidArgumentException("Unit {$ownerId} is not a company or a section; templates belong to one (AD-17).");
        }

        return $owner;
    }

    /** The `templates` column that names an owner of this unit's type. */
    private function ownerColumn(Unit $owner): string
    {
        return $owner->type === UnitType::Section ? 'section_id' : 'company_id';
    }

    /**
     * Templates owned by the unit, a company or a section. A unit's id is unique across types, so either column naming
     * it is that unit's.
     *
     * @param  Builder<Template>  $query
     */
    private function ownedBy(Builder $query, int $ownerId): void
    {
        $query->where('company_id', $ownerId)->orWhere('section_id', $ownerId);
    }

    /** Move the template's active-version pointer to this version. */
    private function point(Template $template, TemplateVersion $version): void
    {
        $template->forceFill(['active_version_id' => $version->id])->save();
        $template->setRelation('activeVersion', $version);
    }
}
