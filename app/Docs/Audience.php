<?php

namespace App\Docs;

/**
 * Who a guide is written for, and which guides a viewer may read (#408). The three are nested: an Admin reads every
 * guide, a leader reads leader and everyone guides, anyone else reads everyone guides only.
 */
enum Audience: string
{
    case Everyone = 'everyone';
    case Leader = 'leader';
    case Admin = 'admin';

    /** Whether a viewer of this audience may read a guide written for `$guide`. */
    public function reads(self $guide): bool
    {
        return $guide->rank() <= $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Everyone => 0,
            self::Leader => 1,
            self::Admin => 2,
        };
    }
}
