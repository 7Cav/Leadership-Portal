<?php

namespace App\Authorization;

use App\Models\Unit;
use App\Models\User;

/**
 * What an Admin is acting as on this request (#379): a person, or a billet (a unit's leader, no person behind it).
 */
final readonly class Acting
{
    /**
     * @param  User  $as  who the request runs as: the person, or the Admin's stand-in holding the billet
     * @param  string  $label  what the red bar names: `SGT Vasquez.R`, or `Section Leader 2/1/A/1-7`
     */
    public function __construct(
        public User $as,
        public ?User $person,
        public ?Unit $billet,
        public string $label,
    ) {}
}
