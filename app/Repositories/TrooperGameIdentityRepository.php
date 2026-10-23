<?php

namespace App\Repositories;

use App\Models\GamePlatform;
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
     * The trooper that owns `$platformId` on `$platform`, as the link row, or null — the capture
     * resolver's lookup (indexed by the `(platform, platform_id)` unique key).
     */
    public function findByPlatformId(GamePlatform $platform, string $platformId): ?TrooperGameIdentity;

    /**
     * Every identity a trooper holds — at most one per platform.
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
     * The trooper's identity on `$platform`, or null, read with a row lock held until the caller's
     * transaction ends — for a write that must only go ahead if that identity is still the one a
     * leader was shown (#176's replace), so a concurrent change cannot land between the check and
     * the write. Call it inside a transaction; outside one the lock is released at once.
     */
    public function lockForTrooper(int $trooperId, GamePlatform $platform): ?TrooperGameIdentity;

    /**
     * Link `$trooperId` to a player on `$platform`, replacing the trooper's own existing identity
     * for that platform (one per platform) rather than adding a second.
     *
     * @throws GameIdentityLinkException when `$platformId` already belongs to a different trooper
     *                                   on that platform; nothing is written and the existing
     *                                   link stays intact — never silently reassigned.
     */
    public function link(int $trooperId, GamePlatform $platform, string $playerName, string $platformId): TrooperGameIdentity;

    /**
     * Remove a trooper's identity on `$platform`. True when a link existed.
     */
    public function unlink(int $trooperId, GamePlatform $platform): bool;
}
