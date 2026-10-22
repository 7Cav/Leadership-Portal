<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How a unit's rollcall post shows fireteams. On a section, its leaders' choice; on a company, a policy over its
 * sections. Null means "not set": the default on a section, "left to the sections" on a company. Only a company sets
 * `sl_asl_lead_fireteams`, whether its sections' SL and ASL may lead a fireteam (off unless set). Portal-owned (AD-22),
 * written only through `FireteamRepository` by the audited fireteam option and policy Commands.
 */
#[Fillable(['unit_id', 'fireteam_order', 'show_fireteams', 'sl_asl_lead_fireteams'])]
class FireteamSetting extends Model
{
    /** FTLs are not shown until a section's leaders turn them on. */
    public const DEFAULT_SHOW = false;

    public const DEFAULT_ORDER = FireteamOrder::LeadersFirst;

    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'fireteam_order' => FireteamOrder::class,
            'show_fireteams' => 'boolean',
            'sl_asl_lead_fireteams' => 'boolean',
        ];
    }

    /**
     * A section's effective fireteam display: the company's policy where it sets one, else the section's own choice,
     * else the default (FTLs hidden, all FTLs above the rest). Whether the SL and ASL may lead a fireteam is the
     * company's alone, off unless it says so. Either setting may be missing.
     *
     * @return array{show: bool, order: FireteamOrder, slAslLead: bool}
     */
    public static function effective(?self $company, ?self $section): array
    {
        return [
            'show' => $company?->show_fireteams ?? $section?->show_fireteams ?? self::DEFAULT_SHOW,
            'order' => $company?->fireteam_order ?? $section?->fireteam_order ?? self::DEFAULT_ORDER,
            'slAslLead' => $company?->sl_asl_lead_fireteams ?? false,
        ];
    }
}
