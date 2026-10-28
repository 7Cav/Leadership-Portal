<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named template of one kind of artifact (#375, AD-17): a company's own, or the regiment-wide default when
 * `company_id` is null. Its text lives in immutable {@see TemplateVersion}s; `active_version_id` points at the one in
 * use. `enabled` is the company command's switch for whether the company's sections may use it; the regiment default
 * is always available whatever it holds.
 *
 * Portal-owned (AD-22), written only by `TemplateVersionRepository` under the audited template Commands. Never deleted.
 */
#[Fillable(['company_id', 'artifact_type', 'name', 'enabled', 'created_by'])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'artifact_type' => ArtifactType::class,
            'enabled' => 'boolean',
            'active_version_id' => 'integer',
        ];
    }

    /** Whether this is the regiment-wide default, which belongs to no company. */
    public function isRegimentDefault(): bool
    {
        return $this->company_id === null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'company_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'active_version_id');
    }
}
