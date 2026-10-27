<?php

namespace App\Models;

use Database\Factories\AuditLogEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per audited state change (AD-16, AD-22): append-only, portal-owned, backed up.
 * Written only by the Repository layer's sole writer of `audit_log` — every attribute
 * here is computed there, never taken from caller-supplied data, so nothing is
 * mass-assignable. Deliberately not naming that class here — Deptrac forbids
 * `Model → Repository` (AD-1), and pint's `fully_qualified_strict_types` fixer turns any
 * `{@see}` reference into a real `use` import, which would quietly create that reverse edge.
 *
 * No `updated_at`: an audit row is never revised, only ever appended.
 */
#[Fillable([])]
class AuditLogEntry extends Model
{
    /** @use HasFactory<AuditLogEntryFactory> */
    use HasFactory;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** The user an Admin was acting as when they did this (#379), or null for an action done as themselves. */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_id');
    }
}
