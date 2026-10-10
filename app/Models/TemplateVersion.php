<?php

namespace App\Models;

use Database\Factories\TemplateVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable version of a company's template for one kind of artifact (Story 3.5, AD-17). The body is written
 * once: changing it is a new row with the next version number, never an update, so the history stays reproducible.
 * An update of an existing row is refused here, at the model, as well as by the repository never issuing one.
 *
 * Portal-owned (AD-22). Which version is current lives in {@see ActiveTemplateVersion}, not on this row, so
 * activating a version never changes it.
 */
class TemplateVersion extends Model
{
    /** @use HasFactory<TemplateVersionFactory> */
    use HasFactory;

    /** Created once and never updated, so the table has no `updated_at`. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A template version is immutable (AD-17): an edit is a new version, never an update.');
        });
    }

    protected function casts(): array
    {
        return [
            'artifact_type' => ArtifactType::class,
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'company_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
