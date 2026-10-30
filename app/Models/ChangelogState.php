<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The changelog's single state row: the deploy id an editor last added an entry for or dismissed the "Changelog
 * outdated" bar on. Portal-owned (AD-22), written only through `ChangelogRepository` by the audited Commands.
 */
#[Fillable(['acknowledged_deploy_id'])]
class ChangelogState extends Model
{
    protected $table = 'changelog_state';
}
