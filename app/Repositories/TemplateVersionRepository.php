<?php

namespace App\Repositories;

use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitTemplateChoice;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `templates`, `template_versions` and `unit_template_choices` (AD-2, AD-17, AD-22). Portal-owned:
 * the audited `Commands/Templates` Commands are its writers.
 *
 * A company keeps named templates (#375), a section may keep its own, and the regiment-wide default is the one template
 * with neither. A unit's id names it whatever its type, so the calls that take an owner's id take a company's or a
 * section's alike. A
 * version row is written once and never updated: an edit is a new row with the next version number of its template,
 * and the template's `active_version_id` is the pointer that moves, so activating never touches a version. A unit's
 * choice of template is one row per unit and kind: a company's active template, or a section leader's pick.
 *
 * Every write for a unit's templates locks that unit's `units` row first (the company, or the section for its own), so
 * they run one at a time; the regiment default's writes lock its template row instead.
 *
 * Each "who" (`$createdBy`, `$chosenBy`, `$copiedBy`) comes with the Admin acting as that person and the unit
 * whose billet an Admin held (#379, as `ActingFor` tells the Commands), both null for a write in one's own name.
 */
interface TemplateVersionRepository
{
    /**
     * Create a named template with its first version, active, in one transaction, owned by `$ownerId`: a company or a
     * section. A company template starts switched on, and becomes the company's active template when the company has
     * none yet, so a company's first template takes effect as soon as it is saved, as an edit always did. A section's
     * template is never picked for it here: its leader picks it, as any other.
     *
     * @throws InvalidArgumentException when `$ownerId` is not a `units` row of type company or section.
     * @throws TemplateNameTakenException when the owner has a template of that name (case aside): nothing is written.
     */
    public function createTemplate(int $ownerId, ArtifactType $type, string $name, string $body, int $createdBy, ?int $createdByAdmin = null, ?int $createdAsUnit = null): Template;

    /**
     * Write a new version of the template and make it the template's active one, in one transaction. The version
     * number is the next for the template: one more than its highest. Two authors racing for the same number cannot
     * both win: the unique index refuses the second, which retries with the next number, so both versions are kept and
     * the pointer ends on the later write.
     *
     * @throws TemplateUnchangedException when `$body` is the active version's body already: nothing is written.
     */
    public function authorVersion(Template $template, string $body, int $createdBy, ?int $createdByAdmin = null, ?int $createdAsUnit = null): TemplateVersion;

    /**
     * Write a new version of the company's active template, creating one ("Company template", switched on and made
     * the company's active one) when the company has none. What an edit of "the company's template" means; kept for the
     * seeders and the tests that set a company up with one template.
     *
     * @throws InvalidArgumentException when `$companyId` is not a `units` row of type company.
     * @throws TemplateUnchangedException when `$body` is the active version's body already: nothing is written.
     */
    public function author(int $companyId, ArtifactType $type, string $body, int $createdBy, ?int $createdByAdmin = null, ?int $createdAsUnit = null): TemplateVersion;

    /**
     * Point the version's template at it, in one transaction. The template comes from the version itself, so the
     * pointer can never name another template's version. The version row is not touched. Returns the version.
     *
     * @throws InvalidArgumentException when the version no longer exists.
     * @throws TemplateUnchangedException when the version is the active one already: nothing is written.
     */
    public function activate(TemplateVersion $version, int $activatedBy): TemplateVersion;

    /**
     * Switch a company template on or off for its sections. Returns the template as it now stands.
     *
     * @throws TemplateUnchangedException when it is switched that way already: nothing is written.
     */
    public function setEnabled(Template $template, bool $enabled): Template;

    /**
     * Record `$template` as `$unit`'s choice of its kind, replacing any earlier one. Returns the choice.
     *
     * @throws TemplateUnchangedException when it is the unit's choice already: nothing is written.
     */
    public function choose(Unit $unit, Template $template, int $chosenBy, ?int $chosenByAdmin = null, ?int $chosenAsUnit = null): UnitTemplateChoice;

    /**
     * Remove `$unit`'s choice of this kind. Returns the removed row, for the audit log.
     *
     * @throws TemplateUnchangedException when the unit has no choice: nothing is written.
     */
    public function clearChoice(Unit $unit, ArtifactType $type): UnitTemplateChoice;

    /** The unit's choice of this kind, with its template and that template's active version loaded, or null. */
    public function choiceFor(int $unitId, ArtifactType $type): ?UnitTemplateChoice;

    /**
     * The company's active template's active version, or null when the company has no active template.
     */
    public function activeFor(int $companyId, ArtifactType $type): ?TemplateVersion;

    /**
     * Every version of the template, newest first, each with its author (and any Admin acting as them, or billet an
     * Admin held, #379) loaded, so a settings screen lists history without lazy loading.
     *
     * @return Collection<int, TemplateVersion>
     */
    public function historyOf(Template $template): Collection;

    /**
     * Every version of the company's active template, newest first (see {@see historyOf()}); empty when it has none.
     *
     * @return Collection<int, TemplateVersion>
     */
    public function history(int $companyId, ArtifactType $type): Collection;

    /**
     * The unit's own templates of this kind (a company's, or a section's), oldest first, each with its active version
     * loaded.
     *
     * @return Collection<int, Template>
     */
    public function templatesOf(int $ownerId, ArtifactType $type): Collection;

    /** The regiment-wide default of this kind, with its active version loaded, or null when there is none. */
    public function regimentDefault(ArtifactType $type): ?Template;

    public function findTemplate(int $id): ?Template;

    public function find(int $id): ?TemplateVersion;

    /**
     * Whether the unit (a company, or a section) holds any template of its own, of any kind. `templates` and
     * `template_versions` both restrict the owner's delete; a version always belongs to one of the owner's templates,
     * so checking the templates covers both.
     */
    public function hasTemplatesFor(Unit $unit): bool;

    /**
     * Copy `$source` into the company as a template of its own (#378), in one transaction under the company's lock: one
     * version, version 1, holding the source's active version's body and author (with any Admin and billet beside it), made the copy's active one. The copy
     * remembers its source (`copied_from_id`) and is switched on or off as `$enabled` says. Only a company template is
     * copied: a section's own moves with the section. Unlike
     * {@see createTemplate()} it never becomes the company's active template, so the company's other sections render
     * what they did. When `$name` is taken (case aside) the copy is numbered: `$name (2)`, `$name (3)`, and so on, so
     * `$name` should leave room for that within the 60-character limit.
     *
     * @throws InvalidArgumentException when `$companyId` is not a company, or the source has no active version.
     */
    public function copyTemplate(Template $source, int $companyId, string $name, bool $enabled, int $copiedBy, ?int $copiedByAdmin = null, ?int $copiedAsUnit = null): Template;
}
