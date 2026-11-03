<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game identity kept on file for a Cav name with no trooper in the portal (someone who left before it began syncing).
 * Not a trooper's identity: a capture never matches it. The roster sync moves it onto the trooper of exactly that Cav
 * name when one appears; `conflict` says why
 * it could not.
 */
#[Fillable(['cav_name', 'cav_name_key', 'game_id', 'id_kind', 'platform_id', 'gamertag', 'gamertag_from_server', 'source', 'created_by', 'conflict'])]
class KeptGameIdentity extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['source' => 'imported'];

    protected function casts(): array
    {
        return ['source' => IdentitySource::class, 'gamertag_from_server' => 'boolean'];
    }

    /** The lower case key a Cav name is matched by: exact, ignoring case only. */
    public static function key(string $cavName): string
    {
        return mb_strtolower(trim($cavName));
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
