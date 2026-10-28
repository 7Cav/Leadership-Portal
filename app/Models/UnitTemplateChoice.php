<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit's choice of template (#375, AD-17). On a company it is the company's active template, the one its sections
 * use when they have not picked one; on a section it is its leader's pick. One row per unit and artifact type.
 *
 * Portal-owned (AD-22), written only by `TemplateVersionRepository` under the audited template Commands.
 */
#[Fillable(['unit_id', 'artifact_type', 'template_id', 'chosen_by'])]
class UnitTemplateChoice extends Model
{
    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'artifact_type' => ArtifactType::class,
            'template_id' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
