<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The changelog's single state row: the deploy id an editor last added an entry for or dismissed the "Changelog
 * outdated" bar on, and the Admin setting `technical_enabled` that makes technical entries available to everyone. Portal-owned (AD-22), written only through `ChangelogRepository` by the audited Commands.
 */
#[Fillable(['acknowledged_deploy_id', 'technical_enabled'])]
class ChangelogState extends Model
{
    protected $table = 'changelog_state';

    protected function casts(): array
    {
        return ['technical_enabled' => 'boolean'];
    }
}
