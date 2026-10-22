<?php

namespace App\Repositories;

use App\Models\GamePlatform;
use App\Models\GamePlayerDismissal;

/**
 * Sole DB access for `game_player_dismissals` (AD-2): the identifiers a leader said are not
 * members (Story 7.7, AD-19). Written by the audited dismiss Command and cleared when the same
 * identifier is linked to a trooper, or when a leader undoes the dismissal (#176).
 */
interface GamePlayerDismissalRepository
{
    /**
     * Record the dismissal; dismissing an identifier again keeps one row and refreshes it.
     */
    public function dismiss(GamePlatform $platform, string $platformId, string $playerName, int $userId): GamePlayerDismissal;

    /**
     * The dismissal of one identifier, or null when it is not dismissed.
     */
    public function find(GamePlatform $platform, string $platformId): ?GamePlayerDismissal;

    /**
     * Drop the dismissal, e.g. because the identifier is now linked. True when one existed.
     */
    public function forget(GamePlatform $platform, string $platformId): bool;

    /**
     * Which of `$platformIds` are dismissed on `$platform`.
     *
     * @param  list<string>  $platformIds
     * @return list<string>
     */
    public function dismissedAmong(GamePlatform $platform, array $platformIds): array;
}
