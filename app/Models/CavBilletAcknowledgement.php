<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An Admin's acknowledgement that a billet sits in two units at once on purpose (2026-11-13), such as the one Cav title
 * of the two Regimental Deputy Chiefs of Staff placed in both their groups. `conflict_key` names the conflict
 * (`title:<name>` or `rule:<position id>`); `placements` is the exact set of billets and units acknowledged, so the
 * warning comes back once any of them sits elsewhere. Written only by `CavBilletRepository`, from the audited Commands.
 */
#[Fillable(['conflict_key', 'title', 'placements'])]
class CavBilletAcknowledgement extends Model {}
