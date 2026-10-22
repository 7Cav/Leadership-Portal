<?php

namespace App\Repositories;

use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `template_versions` and the `active_template_versions` pointer (AD-2, AD-17, AD-22).
 * Portal-owned: the audited `AuthorTemplateVersion` and `ActivateTemplateVersion` Commands are its writers.
 *
 * A version row is written once and never updated. An edit is a new row with the next version number for its
 * (company, artifact type), and the active pointer is a separate row that moves, so activating never touches a
 * version. The pointer's primary key is `(company_id, artifact_type)`, which is what keeps exactly one version active.
 */
interface TemplateVersionRepository
{
    /**
     * Write a new version of the company's template and make it the active one, in one transaction. The version
     * number is the next for the (company, artifact type): 1 for the first, one more than the highest after that.
     * Two authors racing for the same number cannot both win: the unique index refuses the second, which retries
     * with the next number, so both versions are kept and the pointer ends on the later write.
     *
     * The company row is locked first, so authors and activations for a company run one at a time and the
     * same-as-active check is made under that lock.
     *
     * @throws InvalidArgumentException when `$companyId` is not a `units` row of type company.
     * @throws TemplateUnchangedException when `$body` is the active version's body already: nothing is written.
     */
    public function author(int $companyId, ArtifactType $type, string $body, int $createdBy): TemplateVersion;

    /**
     * Point the company's active version of its kind at an existing version, in one transaction. The company and
     * kind come from the version itself, so the pointer can never name another company's version. The version row
     * is not touched. Returns the activated version.
     *
     * @throws InvalidArgumentException when the version no longer exists.
     * @throws TemplateUnchangedException when the version is the active one already: nothing is written.
     */
    public function activate(TemplateVersion $version, int $activatedBy): TemplateVersion;

    /**
     * The company's active version of this kind, or null when none has been authored yet.
     */
    public function activeFor(int $companyId, ArtifactType $type): ?TemplateVersion;

    /**
     * Every version of this kind for the company, newest first, each with its author loaded, so a settings
     * screen lists history without lazy loading.
     *
     * @return Collection<int, TemplateVersion>
     */
    public function history(int $companyId, ArtifactType $type): Collection;

    public function find(int $id): ?TemplateVersion;

    /**
     * Whether the company holds any template version, of any kind. Both `template_versions` and the
     * `active_template_versions` pointer restrict a company's delete; a pointer always names one of the company's
     * own versions, so checking the versions covers both.
     */
    public function hasTemplatesFor(Unit $company): bool;
}
