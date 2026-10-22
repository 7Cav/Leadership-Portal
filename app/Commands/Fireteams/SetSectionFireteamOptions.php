<?php

namespace App\Commands\Fireteams;

use App\Models\FireteamOrder;

/**
 * A section's leaders choose how its rollcall post shows fireteams: whether FTLs are shown at all (`$show`), and where
 * they go when shown (`$order`). A null leaves that choice as it is. Handled by {@see SetSectionFireteamOptionsHandler}.
 */
final readonly class SetSectionFireteamOptions
{
    public function __construct(
        public int $sectionId,
        public ?bool $show = null,
        public ?FireteamOrder $order = null,
    ) {}
}
