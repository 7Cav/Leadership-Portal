<?php

namespace App\Models;

/**
 * What a game server is for (#389). Only a **training** server is read for attendance: a practice happens there,
 * and reading a public one would credit troopers who were only playing publicly.
 */
enum GameServerType: string
{
    case Training = 'training';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Training => 'Training',
            self::Public => 'Public',
        };
    }
}
