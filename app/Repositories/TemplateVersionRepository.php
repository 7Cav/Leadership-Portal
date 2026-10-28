<?php

namespace App\Repositories;

use App\Models\ArtifactType;
use App\Models\CompanyTemplatePolicy;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitTemplateChoice;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `templates`, `template_versions`, `unit_template_choices` and `company_template_policies` (AD-2,
 * AD-17, AD-22). Portal-owned:
 * the audited `Commands/Templates` Commands are its writers.
 *
 * A company keeps named templates (#375), and the regiment-wide default is the one template with no company. A
 * version row is written once and never updated: an edit is a new row with the next version number of its template,
 * and the template's `active_version_id` is the pointer that moves, so activating never touches a version. A unit's
 * choice of template is one row per unit and kind: a company's active template, or a section leader's pick.
 *
 * Every write for a company's templates locks that company's `units` row first, so they run one at a time; the regiment
 * default's writes lock its template row instead.
 */
interface TemplateVersionRepository
{
    /**
     * Create a named template with its first version, active, in one transaction. A company template starts switched
     * on, and becomes the company's active template when the company has none yet, so a company's first template
     * takes effect as soon as it is saved, as an edit always did.
     *
     * @throws InvalidArgumentException when `$companyId` is not a `units` row of type company.
     * @throws TemplateNameTakenException when the company has a template of that name (case aside): nothing is written.
     */
    public function createTemplate(int $companyId, ArtifactType $type, string $name, string $body, int $createdBy): Template;

    /**
     * Write a new version of the template and make it the template's active one, in one transaction. The version
     * number is the next for the template: one more than its highest. Two authors racing for the same number cannot
     * both win: the unique index refuses the second, which retries with the next number, so both versions are kept and
     * the pointer ends on the later write.
     *
     * @throws TemplateUnchangedException when `$body` is the active version's body already: nothing is written.
     */
    public function authorVersion(Template $template, string $body, int $createdBy): TemplateVersion;

    /**
     * Write a new version of the company's active template, creating one ("Company template", switched on and made
     * the company's active one) when the company has none. What an edit of "the company's template" means; kept for the
     * seeders and the tests that set a company up with one template.
     *
     * @throws InvalidArgumentException when `$companyId` is not a `units` row of type company.
     * @throws TemplateUnchangedException when `$body` is the active version's body already: nothing is written.
     */
    public function author(int $companyId, ArtifactType $type, string $body, int $createdBy): TemplateVersion;

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
    public function choose(Unit $unit, Template $template, int $chosenBy): UnitTemplateChoice;

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
     * Every version of the template, newest first, each with its author loaded, so a settings screen lists history
     * without lazy loading.
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
     * The company's own templates of this kind, oldest first, each with its active version loaded.
     *
     * @return Collection<int, Template>
     */
    public function templatesOf(int $companyId, ArtifactType $type): Collection;

    /** The regiment-wide default of this kind, with its active version loaded, or null when there is none. */
    public function regimentDefault(ArtifactType $type): ?Template;

    public function findTemplate(int $id): ?Template;

    public function find(int $id): ?TemplateVersion;

    /**
     * Whether the company holds any template, of any kind. `templates` and `template_versions` both restrict a
     * company's delete; a version always belongs to one of the company's templates, so checking the templates covers
     * both.
     */
    public function hasTemplatesFor(Unit $company): bool;

    /**
     * Whether the company's own command may set its templates (#376): true unless its battalion command switched that
     * off.
     */
    public function companyEditsTemplates(int $companyId): bool;

    /**
     * Record whether the company's own command may set its templates. Returns the policy as it now stands.
     *
     * @throws TemplateUnchangedException when it is set that way already (a company with no row counts as on).
     */
    public function setCompanyEditsTemplates(Unit $company, bool $edits, int $setBy): CompanyTemplatePolicy;
}
