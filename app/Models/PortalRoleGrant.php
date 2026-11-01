<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
#[Fillable(['user_id', 'unit_id', 'expires_at', 'renew_every_days', 'expiry_set_by'])]
class PortalRoleGrant extends Model
{
    /** @use HasFactory<PortalRoleGrantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'renew_every_days' => 'integer',
            'expiry_set_by' => 'integer',
        ];
    }

    /** Whether the grant has an end date that has passed at `$now`: it gives no scope from that moment. */
    public function isPastEnd(CarbonInterface $now): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan($now);
    }

    /** A grant that lapses at its date and waits to be renewed, rather than ending for good. */
    public function renews(): bool
    {
        return $this->expires_at !== null && $this->renew_every_days !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
