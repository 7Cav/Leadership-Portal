<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A template carried along with a platoon or section moved to another company (#378, AD-17): its sections may go on
 * using it there, unless the new company's battalion has switched the company's own template editing off. Portal-owned
 * (AD-22), written only by `TemplateVersionRepository` under the audited `CarryUnitTemplates`.
 */
#[Fillable(['unit_id', 'template_id', 'from_company_id', 'carried_by'])]
class TemplateCarry extends Model
{
    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'template_id' => 'integer',
            'from_company_id' => 'integer',
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

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_company_id');
    }
}
