<?php

namespace App\Commands\GameIdentities;

/**
 * What a platform lookup ({@see PlatformLookup}) found for one player ID on one game.
 *
 * `found`: a server knows the player. `kind` is the game's ID kind for their platform, null when CRCON names a platform
 * the portal does not map or the game does not ask for that kind; `platform` is CRCON's own word for it and `name`
 * their current in-game name. `not_seen`: every server answered and none has seen the player. `not_checked`: no server
 * could be asked (none set up, an outage, or the run's lookups were used up), so nothing is known.
 */
final readonly class PlatformLookupResult
{
    public const FOUND = 'found';

    public const NOT_SEEN = 'not_seen';

    public const NOT_CHECKED = 'not_checked';

    private function __construct(
        public string $status,
        public ?string $kind = null,
        public ?string $platform = null,
        public ?string $name = null,
    ) {}

    public static function found(?string $kind, ?string $platform, ?string $name): self
    {
        return new self(self::FOUND, $kind, $platform, $name);
    }

    public static function notSeen(): self
    {
        return new self(self::NOT_SEEN);
    }

    public static function notChecked(): self
    {
        return new self(self::NOT_CHECKED);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    /** @return array{status: string, kind: string|null, platform: string|null, name: string|null} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'kind' => $this->kind, 'platform' => $this->platform, 'name' => $this->name];
    }

    /** @param  array{status: string, kind: string|null, platform: string|null, name: string|null}  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['status'], $data['kind'] ?? null, $data['platform'] ?? null, $data['name'] ?? null);
    }
}
