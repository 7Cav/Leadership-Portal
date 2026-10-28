<?php

namespace App\Models;

/**
 * The shape a kind of player ID has (#450), the one list of them: a kind a game asks for (#428, `games.id_kinds`) is
 * matched here by its name, and a kind with no entry keeps the free check (present, at most 255 characters). A new
 * kind with a known shape gets its rule in {@see self::for()}, nowhere else. The server checks it on every save from
 * the Game identities screen; the page only shows the hint. An ID a game server reported (linking an unmatched
 * player, 7.7) skips it: that is the ID the capture matches on, whatever shape it has.
 *
 * Only shapes that are certain are listed. A Steam ID is the SteamID64 the capture reads from CRCON and Nightwatch.
 * An Epic ID is the Epic account id, 32 hex characters, which CRCON reports in lower case: one typed in upper case is
 * stored lower case, so it is the value the capture compares. Xbox, PlayStation and other kinds are not checked.
 */
final readonly class IdKindFormat
{
    private function __construct(
        public string $kind,
        private string $pattern,
        public string $hint,
        private bool $lowercase = false,
    ) {}

    /** The rule for a kind, by its name (case and spaces ignored, so "Steam ID" and "SteamID" are one), or null. */
    public static function for(string $kind): ?self
    {
        return match (strtolower(preg_replace('/\s+/', '', $kind) ?? '')) {
            'steamid', 'steamid64' => new self($kind, '/^7656119\d{10}$/', 'A Steam ID is 17 digits starting 7656119, for example 76561198012345678.'),
            'epicid' => new self($kind, '/^[0-9a-f]{32}$/', 'An Epic ID is 32 characters, the digits 0 to 9 and the letters a to f.', lowercase: true),
            default => null,
        };
    }

    /** The ID as it is stored: an Epic ID lower case, anything else as given. */
    public function canonical(string $id): string
    {
        return $this->lowercase ? strtolower($id) : $id;
    }

    /** Whether `$id`, already {@see self::canonical()}, has the kind's shape. */
    public function accepts(string $id): bool
    {
        return preg_match($this->pattern, $id) === 1;
    }

    /** What a leader is told when an ID does not have the shape. */
    public function message(): string
    {
        return "This is not a valid {$this->kind}. {$this->hint}";
    }
}
