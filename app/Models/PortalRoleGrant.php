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
 * Portal-owned (AD-22): the creating/revoking writer is Story 1.12's audited Command, so
 * nothing here is mass-assignable from caller-supplied data yet.
 */
#[Fillable([])]
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
