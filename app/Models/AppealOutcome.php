<?php

namespace App\Models;

/**
 * The appeal authority's decision on an appealed LOR, as the form's "Appeal outcome" puts it (7CAV-R-043, #565). The
 * decision is final. Backed so the DB enum column and this type stay in lockstep.
 */
enum AppealOutcome: string
{
    case Amended = 'amended';
    case NotAmended = 'not_amended';

    public function label(): string
    {
        return match ($this) {
            self::Amended => 'Have amended',
            self::NotAmended => 'Have not amended',
        };
    }
}
