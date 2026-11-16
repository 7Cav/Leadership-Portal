<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A game the regiment plays (#388), kept by an Admin: what it is called, which kinds of player identifier it asks for ("Steam ID", "Epic ID", #428), and which built-in telemetry interface reads its attendance. Portal-owned (AD-22) — written
 * only by the audited `app/Commands/Games` Commands through `GameRepository`. `slug` never changes once the game
 * exists: routes and requests name the game by it. A retired game stays on every identity and capture that uses it,
 * but cannot be newly linked to a trooper (and, once games are assigned to units, #390, newly assigned). The shape an ID
 * of each kind must have is listed in {@see IdKindFormat}, by kind name (#450). `abbreviation` is the game's short code
 * (HLL, HLLV, ARMA), shown as the game chip on the row of the highest line unit in an AO that plays it (usually a battalion) in the org tree.
 * `event_tags` is the text in a 7Cav calendar event's title that marks the event as the game's (`[HLL]`, `SQUAD |`, #751),
 * so a section's roll call lists the events of the game it plays; null or empty lists none.
 */
#[Fillable(['slug', 'name', 'abbreviation', 'id_kinds', 'interface', 'event_tags', 'retired_at'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Every game has a short code: one created without it (a seeder, a factory state) gets one made from its name,
        // kept unique with a digit as `AddGame` does.
        static::creating(function (Game $game): void {
            if ($game->abbreviation === null || trim($game->abbreviation) === '') {
                $base = self::abbreviationFor((string) $game->name);
                $code = $base;
                for ($n = 2; self::query()->where('abbreviation', $code)->exists(); $n++) {
                    $code = substr($base, 0, 6 - strlen((string) $n)).$n;
                }
                $game->abbreviation = $code;
            }
        });

        // Routes and requests name a game by its slug, so changing one would break them. A query-builder update
        // bypasses this, as for any model event; `GameRepository::update()` has no slug to change.
        static::updating(function (Game $game): void {
            if ($game->isDirty('slug')) {
                throw new LogicException("A game's slug never changes (#388): {$game->getOriginal('slug')}.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'id_kinds' => 'array',
            'event_tags' => 'array',
            'interface' => TelemetryInterface::class,
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** What an abbreviation must look like: 2 to 6 uppercase letters or digits. */
    public const ABBREVIATION_PATTERN = '/^[A-Z0-9]{2,6}$/';

    /**
     * The short code a game gets when none is given: the initials of a name of several words ("Hell Let Loose" is HLL),
     * else the first letter and the consonants after it ("Squad" is SQD), at most 6, at least 2.
     */
    public static function abbreviationFor(string $name): string
    {
        $words = preg_split('/[^A-Z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['GAME'];
        $code = count($words) > 1
            ? implode('', array_map(fn (string $word) => $word[0], $words))
            : $words[0][0].preg_replace('/[AEIOU]/', '', substr($words[0], 1));

        $code = substr($code, 0, 6);

        return strlen($code) >= 2 ? $code : substr($words[0].'X', 0, 2);
    }

    /** The kind a new identity takes when none is chosen, and the one every identity from before kinds existed took: the first. */
    public function defaultIdKind(): string
    {
        return $this->id_kinds[0];
    }

    /** The ID kind a game's servers log every player by, whatever their platform: CRCON on HLL Vietnam logs an EOS ID. */
    public const LOGGED_ID_KIND = 'EOS ID';

    /**
     * The only kind that counts as linked for this game (15NOV26), or null when any kind does. A game whose kinds include
     * {@see LOGGED_ID_KIND} is read by that ID alone, so a Steam, Epic, Xbox or PlayStation ID on it still leaves the
     * trooper to link: capture only finds them through their CRCON profile, never by the ID itself.
     */
    public function requiredIdKind(): ?string
    {
        return in_array(self::LOGGED_ID_KIND, $this->id_kinds ?? [], true) ? self::LOGGED_ID_KIND : null;
    }

    public function hasIdKind(string $kind): bool
    {
        return in_array($kind, $this->id_kinds, true);
    }

    /** The most calendar title tags a game may have, and the longest one. */
    public const MAX_EVENT_TAGS = 20;

    public const MAX_EVENT_TAG_LENGTH = 50;

    /**
     * The calendar title tags that mark this game's events (#751), as an Admin set them on Settings > Games.
     *
     * @return list<string>
     */
    public function eventTags(): array
    {
        return array_values(array_filter($this->event_tags ?? [], fn (mixed $tag) => is_string($tag) && trim($tag) !== ''));
    }

    /**
     * Whether a calendar event's title carries one of this game's tags anywhere in it, ignoring case: `[HLL]` marks
     * `[HLL] Operation Trenched n' Tuesday`, `SQUAD |` marks `SQUAD | Squad Saturday @1900Z`. A game with no tags
     * marks nothing.
     */
    public function marksEvent(string $title): bool
    {
        foreach ($this->eventTags() as $tag) {
            if (mb_stripos($title, trim($tag)) !== false) {
                return true;
            }
        }

        return false;
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }
}
