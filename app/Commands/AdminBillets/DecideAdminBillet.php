<?php

namespace App\Commands\AdminBillets;

/**
 * Approve or reject a Regimental HQ billet as an Admin billet, with the reason the audit
 * log records. Handled by {@see DecideAdminBilletHandler}.
 */
final readonly class DecideAdminBillet
{
    public function __construct(
        public int $billetId,
        public bool $approve,
        public ?string $reason,
    ) {}
}
