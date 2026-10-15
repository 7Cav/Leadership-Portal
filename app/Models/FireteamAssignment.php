<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One trooper's fireteam role in a section: a fireteam leader (FTL), or a member of the FTL `leader_trooper_id` names.
 * Portal-owned (AD-22), written only through `FireteamRepository` by the audited `SetFireteamRole` Command.
 */
#[Fillable(['section_id', 'trooper_id', 'role', 'leader_trooper_id'])]
class FireteamAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'role' => FireteamRole::class,
            'section_id' => 'integer',
            'trooper_id' => 'integer',
            'leader_trooper_id' => 'integer',
        ];
    }
}
