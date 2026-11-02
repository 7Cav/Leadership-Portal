<?php

namespace App\Models;

/**
 * How a game identity row was made (#174), stored in `trooper_game_identities.source` and shown where identities are
 * listed and in the audit log.
 */
enum IdentitySource: string
{
    /** Entered by a leader for one game on Game identities. */
    case Typed = 'typed';

    /** Made by linking a Steam or Epic account to every game that asks for it, or by an Admin's backfill. */
    case FromAccount = 'from_account';

    /** Made by linking an unmatched player a capture reported. */
    case FromRollcall = 'from_rollcall';

    /** Added or replaced by a leader's bulk import from a text file on Game identities. */
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::Typed => 'Typed',
            self::FromAccount => 'From account',
            self::FromRollcall => 'From rollcall',
            self::Imported => 'Imported',
        };
    }
}
