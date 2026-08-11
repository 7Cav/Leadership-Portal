<?php

namespace App\Models;

use Database\Factories\PortalRoleGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit, audited scope for a non-admin staff user whose access the roster can't imply
 * (AD-7) — attaches them to a unit node so they are never scopeless (AD-8, AD-18).
 * Portal-owned (AD-22): `user_id`/`unit_id` are mass-assignable because the sole writer is
 * `PortalRoleGrantRepository::grant()`, itself only ever reached through the audited
 * `GrantStaffScope` Command (Story 1.12) — never directly from caller-supplied request data.
 */
#[Fillable(['user_id', 'unit_id'])]
class PortalRoleGrant extends Model
{
    /** @use HasFactory<PortalRoleGrantFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
