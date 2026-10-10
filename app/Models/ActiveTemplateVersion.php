<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which version is current for a company and kind of artifact (Story 3.5, AD-17): the active-version pointer. The
 * composite primary key `(company_id, artifact_type)` is what keeps exactly one version active, since moving the
 * pointer rewrites this one row. Kept apart from {@see TemplateVersion} so activating never touches a version.
 *
 * Portal-owned (AD-22), written only by `TemplateVersionRepository` under the audited template Commands, with an upsert
 * on the key rather than through the model, so nothing on it is mass-assignable.
 */
class ActiveTemplateVersion extends Model
{
    /** The key is composite, so Eloquent's single-column key handling does not apply to this row. */
    protected $primaryKey = ['company_id', 'artifact_type'];

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'artifact_type' => ArtifactType::class,
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }
}
