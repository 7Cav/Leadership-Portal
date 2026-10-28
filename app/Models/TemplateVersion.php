<?php

namespace App\Models;

use Database\Factories\TemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable version of a {@see Template} (Story 3.5, #375, AD-17). The body is written once: changing it is a new
 * row with the next version number of its template, never an update, so the history stays reproducible. An update of
 * an existing row is refused here, at the model, as well as by the repository never issuing one.
 *
 * `company_id` or `section_id` repeats the template's owner (both null for the regiment default) so a unit's history
 * is one indexed read. Portal-owned (AD-22). Which version is current lives on the template (`active_version_id`), not on this row,
 * so activating a version never changes it.
 */
#[Fillable(['template_id', 'company_id', 'section_id', 'artifact_type', 'version', 'body', 'created_by', 'created_by_admin', 'created_as_unit'])]
class TemplateVersion extends Model
{
    /** @use HasFactory<TemplateVersionFactory> */
    use HasFactory;

    /** Created once and never updated, so the table has no `updated_at`. */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A template version is immutable (AD-17): an edit is a new version, never an update.');
        });

        // Query-builder updates and deletes bypass model events, so these two guards are not the whole defence:
        // the database's `restrictOnDelete` on the pointer and the unique (template, version) index are.
        static::deleting(function (): never {
            throw new LogicException('A template version is immutable (AD-17): it is never deleted, only superseded.');
        });
    }

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'company_id' => 'integer',
            'section_id' => 'integer',
            'artifact_type' => ArtifactType::class,
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'company_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The Admin who wrote this version while acting as `created_by` (#379), or null. */
    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin');
    }

    /** The unit whose billet `created_by`, an Admin, held when writing this version (#379), or null. */
    public function authorUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'created_as_unit');
    }
}
