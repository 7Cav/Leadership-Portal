<?php

namespace App\Commands\Fireteams;

use App\Models\FireteamOrder;

/**
 * A company's command sets its fireteam policy over every section beneath it: `$show` shows or hides FTLs on every
 * section's post, `$order` LeadersFirst keeps every section on "all FTLs above the rest", and a null leaves that
 * choice to the sections. `$slAslLead` lets the sections' SL and ASL lead a fireteam as well (off by default). The
 * whole policy is given each time. Handled by {@see SetCompanyFireteamPolicyHandler}.
 */
final readonly class SetCompanyFireteamPolicy
{
    public function __construct(
        public int $companyId,
        public ?bool $show,
        public ?FireteamOrder $order,
        public bool $slAslLead = false,
    ) {}
}
