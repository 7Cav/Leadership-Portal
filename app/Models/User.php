<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The portal-owned identity link (AD-22): a forum user we've seen sign in via SSO.
 * Holds no secret and no password (AD-6) — authentication happens entirely at
 * 7cav.us. `cav_id` is nullable until the Cav roster enrichment (Story 1.6) links
 * it; `is_admin` (AD-7) is persisted here but read later by ScopeResolver (1.11).
 *
 * `is_admin` is deliberately NOT mass-assignable — it's the one privilege bit, and
 * SSO gives the forum no legitimate way to set it. It defaults to false at the DB
 * level and is only ever flipped through an explicit, audited path.
 *
 * The flag is not the only way to be an Admin: an approved Regimental HQ or S6 HQ billet makes its holder one too
 * (policy, 2026-10-28). That is worked out by `ScopeResolver::isAdmin()` on every request and never stored
 * here: no save of this model ever writes it to `is_admin`.
 */
#[Fillable(['forum_user_id', 'cav_id', 'username', 'email'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Set on a copy of an account that a request runs as or previews (an Admin acting as this person, the roster account
     * of a trooper with no account): such a copy is never an Admin by billet, only by the flag its attributes carry.
     * A plain property, not an attribute, so no save, fill or update can ever store it.
     */
    public bool $notAdminByBillet = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forum_user_id' => 'integer',
            'cav_id' => 'integer',
            'is_admin' => 'boolean',
        ];
    }
}
