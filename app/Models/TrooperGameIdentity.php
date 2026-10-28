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
 * `name_mixes_scripts` flags a player name that mixes alphabets (#450), set here whenever the name is saved; null on a
 * row saved before the flag existed. {@see self::problems()} is what an Admin is shown for a row that breaks the rules
 * new saves follow.
 */
#[Fillable(['trooper_id', 'game_id', 'player_name', 'platform_id', 'id_kind'])]
class TrooperGameIdentity extends Model
{
    /** @use HasFactory<TrooperGameIdentityFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (TrooperGameIdentity $identity): void {
            if ($identity->isDirty('player_name') || $identity->name_mixes_scripts === null) {
                $identity->name_mixes_scripts = GameIdentityText::mixesScripts((string) $identity->player_name);
            }
        });
    }

    protected function casts(): array
    {
        return ['name_mixes_scripts' => 'boolean'];
    }

    /** Whether the name mixes alphabets: the stored flag, or worked out for a row saved before there was one. */
    public function nameMixesScripts(): bool
    {
        return $this->name_mixes_scripts ?? GameIdentityText::mixesScripts((string) $this->player_name);
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

        if (! GameIdentityText::isClean($this->player_name)) {
            $problems[] = 'The player name has hidden characters or extra spaces.';
        }

        if ($this->nameMixesScripts()) {
            $problems[] = 'The player name mixes alphabets. Check it against the game.';
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
