<?php

namespace App\Docs;

/**
 * A guide's entry on the "Start here" list (#412): its label there, who it is listed for, and its place in the list.
 * Never wider than the guide's own audience, so an item always opens a guide its reader may read.
 */
final readonly class ChecklistItem
{
    public function __construct(
        public string $label,
        public Audience $audience,
        public int $order,
    ) {}
}
