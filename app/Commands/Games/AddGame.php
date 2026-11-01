<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\TelemetryInterface;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Str;

/**
 * Add a game the regiment plays (#388). Admin-only — enforced by the controller (AD-6) before this runs, as for
 * every Settings write. The slug is made from the name once, here, and never changes: routes and the rollcall's
 * requests name the game by it. A name whose slug is taken gets the next free `-2`, `-3`. Audited (AD-16); a
 * reason is not required.
 */
class AddGame
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @param  list<string>  $idKinds  the kinds of player ID the game asks for, the first being the default
     *
     * @throws InvalidGameException when the name has nothing a slug can be made from.
     */
    public function handle(User $actor, string $name, array $idKinds, TelemetryInterface $interface, ?string $abbreviation = null): Game
    {
        $base = Str::slug($name);

        if ($base === '') {
            throw InvalidGameException::nameWithoutLetters();
        }

        $slug = $base;

        for ($n = 2; $this->games->findBySlug($slug) !== null; $n++) {
            $slug = "{$base}-{$n}";
        }

        // None given: made from the name, with a digit when that code is taken.
        if ($abbreviation === null || trim($abbreviation) === '') {
            $base = Game::abbreviationFor($name);
            $abbreviation = $base;
            for ($n = 2; $this->games->findByAbbreviation($abbreviation) !== null; $n++) {
                $abbreviation = substr($base, 0, 6 - strlen((string) $n)).$n;
            }
        }
        $abbreviation = strtoupper(trim($abbreviation));

        return $this->audit->record(
            actorId: $actor->id,
            action: 'game.added',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->games->create($slug, trim($name), $abbreviation, array_values(array_map('trim', $idKinds)), $interface),
        );
    }
}
