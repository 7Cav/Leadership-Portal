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
 */
#[Fillable(['forum_user_id', 'cav_id', 'username', 'email'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
