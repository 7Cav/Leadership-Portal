<?php

namespace App\Commands\AdminBillets;

/**
 * Approve or reject a Regimental HQ billet as an Admin billet (decided by the S6, 2026-10-28), with the reason the audit
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
