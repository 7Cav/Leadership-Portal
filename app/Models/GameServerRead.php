<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One capture's read of one game server (#389): whether it answered and when, for the Servers page. Written by the
 * capture run through `GameServerRepository`; a fallback read is recorded against the fallback server.
 */
#[Fillable(['game_server_id', 'sync_run_id', 'succeeded', 'reason', 'read_at'])]
class GameServerRead extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'read_at' => 'immutable_datetime',
        ];
    }
}
