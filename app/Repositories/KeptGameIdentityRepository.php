<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\Gamertag;
use App\Models\KeptGameIdentity;
use Illuminate\Support\Collection;

/**
 * Game identities kept on file for Cav names with no trooper in the portal ({@see KeptGameIdentity}).
 */
interface KeptGameIdentityRepository
{
    /** @return Collection<int, KeptGameIdentity> every kept identity, with its game and creator, by name */
    public function all(): Collection;

    public function find(int $id): ?KeptGameIdentity;

    /**
     * @param  list<string>  $keys  lower case Cav names
     * @return Collection<int, KeptGameIdentity>
     */
    public function forNames(array $keys): Collection;

    public function findByPlatformId(Game $game, string $platformId): ?KeptGameIdentity;

    /** The kept identity of this name on this game, locked for update. */
    public function lockForName(string $cavName, Game $game): ?KeptGameIdentity;

    /** Keep (or replace) the identity of this name on this game. */
    public function keep(string $cavName, Game $game, string $platformId, string $idKind, ?Gamertag $gamertag, int $createdBy): KeptGameIdentity;

    public function delete(KeptGameIdentity $kept): void;

    /**
     * Move each kept identity whose Cav name is now exactly one trooper's onto that trooper as an `imported` identity,
     * unless they already hold one on that game or another trooper holds the ID; such a row stays kept and its
     * `conflict` says why. One query when nothing matches.
     *
     * @return array{attached: int, conflicts: int}
     */
    public function attachRejoined(): array;
}
