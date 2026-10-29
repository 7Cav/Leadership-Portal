<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\TrooperGameIdentity;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `trooper_game_identities` (AD-2, AD-19). Portal-owned (AD-22): 7.2's audited
 * `SetTrooperGameIdentity` Command is its one writer and the capture resolver (7.5) reads it. It
 * is decoupled from `attendance` — nothing here touches a mark already stamped from a capture.
 */
interface TrooperGameIdentityRepository
{
    /**
     * The trooper that owns `$platformId` in `$game`, as the link row, or null — the capture
     * resolver's lookup (indexed by the `(game_id, platform_id)` unique key).
     */
    public function findByPlatformId(Game $game, string $platformId): ?TrooperGameIdentity;

    /**
     * {@see findByPlatformId()} for many players at once (audit 2, L7): the link rows in `$game` whose ID is one of
     * `$platformIds`, compared as the database compares them (without case on MySQL), so the capture reads every
     * player it saw in one query per few hundred IDs.
     *
     * @param  list<string>  $platformIds
     * @return Collection<int, TrooperGameIdentity>
     */
    public function findByPlatformIds(Game $game, array $platformIds): Collection;

    /**
     * Every identity a trooper holds — at most one per game.
     *
     * @return Collection<int, TrooperGameIdentity>
     */
    public function forTrooper(int $trooperId): Collection;

    /**
     * The identities of all `$trooperIds`, in one query — the link screen's batched read.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, TrooperGameIdentity>
     */
    public function forTroopers(array $trooperIds): Collection;

    /**
     * Every identity on every game, with its game and trooper: the Admin's list of identities to check (#450).
     *
     * @return Collection<int, TrooperGameIdentity>
     */
    public function all(): Collection;

    /**
     * The trooper's identity in `$game`, or null, read with a row lock held until the caller's
     * transaction ends — for a write that must only go ahead if that identity is still the one a
     * leader was shown (#176's replace), so a concurrent change cannot land between the check and
     * the write. Call it inside a transaction; outside one the lock is released at once.
     */
    public function lockForTrooper(int $trooperId, Game $game): ?TrooperGameIdentity;

    /**
     * Link `$trooperId` to a player in `$game`, replacing the trooper's own existing identity
     * in that game (one per game) rather than adding a second.
     *
     * `$idKind` is which of the game's ID kinds the id was entered as (#428); the repository stores it as given, the
     * Command checks it against the game.
     *
     * @throws GameIdentityLinkException when `$platformId` already belongs to a different trooper
     *                                   in that game, whatever kind either was entered as; nothing is
     *                                   written and the existing link stays intact — never silently reassigned.
     */
    public function link(int $trooperId, Game $game, string $playerName, string $platformId, string $idKind): TrooperGameIdentity;

    /**
     * The ID kinds of `$game` that a linked identity uses (#428), so a kind in use is never removed from the game.
     *
     * @return list<string>
     */
    public function kindsInUse(Game $game): array;

    /**
     * Remove a trooper's identity in `$game`. True when a link existed.
     */
    public function unlink(int $trooperId, Game $game): bool;
}
