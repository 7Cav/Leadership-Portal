<?php

namespace App\Models;

/**
 * What a template versions (Story 3.5, AD-17). One table holds every kind, so the weekly report's layout can reuse
 * it later without a new schema. Backed so the stored `template_versions.artifact_type` and this type stay in lockstep;
 * a value neither recognises fails loudly rather than rendering the wrong artifact.
 */
enum ArtifactType: string
{
    /** The BBCode a section leader copies into the weekly rollcall thread. */
    case RollcallBbcode = 'rollcall_bbcode';

    public function label(): string
    {
        return match ($this) {
            self::RollcallBbcode => 'Roll call BBCode',
        };
    }
}
