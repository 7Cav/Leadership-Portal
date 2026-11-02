<?php

namespace App\Models;

use Database\Factories\TrooperGameIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trooper's linked in-game identity in one game (AD-19): the seam auto-capture resolves an
 * observed player against. Portal-owned (AD-22) — written only by an audited Command through
 * `TrooperGameIdentityRepository`, never by a `Sync/` handler, and backed up (AD-5). Decoupled
 * from `attendance`: unlinking never touches a mark already stamped from a past capture. `id_kind` is which
 * of its game's ID kinds (#428) `platform_id` was entered as; it is a label, and the game's (game_id, platform_id)
 * key decides who owns an id, whichever kind it was.
 *
 * The row holds no player name (#174): matching is by `platform_id`, and a name would only go stale. Where an identity
 * is shown, its ID is shown. {@see self::problems()} is what an Admin is shown for a row that breaks the rules new saves
 * follow.
 *
 * `source` is how the row was made (#174, {@see IdentitySource}): typed for one game, from a shared Steam or Epic account,
 * or from a capture's unmatched player.
 *
 * `gamertag` is the player's current in-game name, for Xbox, PlayStation and Epic players whose ID means nothing to a
 * leader: typed by a leader, or filled in from a game server (`gamertag_from_server`). It is shown only and never
 * matched on, since a player can change it at any time.
 */
#[Fillable(['trooper_id', 'game_id', 'platform_id', 'id_kind', 'gamertag', 'gamertag_from_server', 'source'])]
class TrooperGameIdentity extends Model
{
    /** @use HasFactory<TrooperGameIdentityFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['source' => 'typed'];

    protected function casts(): array
    {
        return ['source' => IdentitySource::class, 'gamertag_from_server' => 'boolean'];
    }

    /**
     * What a save today would refuse or change about this row (#450), as sentences for an Admin; empty when nothing.
     * Read-only: the row itself is never changed here.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $format = IdKindFormat::for((string) $this->id_kind);

        if ($format !== null && ($format->canonical($this->platform_id) !== $this->platform_id || ! $format->accepts($this->platform_id))) {
            $problems[] = $format->message();
        }

        if (! GameIdentityText::isClean($this->platform_id)) {
            $problems[] = 'The ID has hidden characters or extra spaces.';
        }

        return $problems;
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
