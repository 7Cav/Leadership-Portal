<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a company's own command may set its rollcall templates (#376), as its battalion command chose. A company with
 * no row may. Portal-owned (AD-22), written only by `TemplateVersionRepository` under the audited
 * `SetCompanyTemplatePolicy` Command.
 */
#[Fillable(['company_id', 'company_edits_templates', 'set_by', 'set_by_admin', 'set_as_unit'])]
class CompanyTemplatePolicy extends Model
{
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'company_edits_templates' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'company_id');
    }
}
