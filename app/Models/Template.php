<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named template of one kind of artifact (#375, AD-17): a company's own (`company_id`), a section's own
 * (`section_id`), or the regiment-wide default when it has neither. Never both. Its text lives in immutable
 * {@see TemplateVersion}s; `active_version_id` points at the one in use. `enabled` is the company command's switch for
 * whether the company's sections may use it; the regiment default, and a section's own template for that section, are
 * always available whatever it holds. A section's template is keyed to the section, so it moves with it. `copied_from_id` is the template it was copied from, when a platoon moved here
 * from another company brought it along (#378): the copy is this company's own from then on.
 *
 * Portal-owned (AD-22), written only by `TemplateVersionRepository` under the audited template Commands. Never deleted.
 */
#[Fillable(['company_id', 'section_id', 'artifact_type', 'name', 'enabled', 'created_by', 'created_by_admin', 'created_as_unit'])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'section_id' => 'integer',
            'artifact_type' => ArtifactType::class,
            'enabled' => 'boolean',
            'active_version_id' => 'integer',
            'copied_from_id' => 'integer',
        ];
    }

    /** Whether this is the regiment-wide default, which belongs to no company and no section. */
    public function isRegimentDefault(): bool
    {
        return $this->company_id === null && $this->section_id === null;
    }

    /** Whether this is a section's own template, which only that section's rollcall may use. */
    public function isSectionTemplate(): bool
    {
        return $this->section_id !== null;
    }

    /** The unit that owns it: its company or its section. Null for the regiment default. */
    public function ownerId(): ?int
    {
        return $this->company_id ?? $this->section_id;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'company_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'section_id');
    }

    /** The template this one was copied from (#378), or none. */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'active_version_id');
    }
}
