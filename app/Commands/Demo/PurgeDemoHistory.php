<?php

namespace App\Commands\Demo;

/**
 * Delete every demo row (#115) of `$unitId`'s whole subtree, and nothing else. Handled by
 * {@see PurgeDemoHistoryHandler}.
 */
final readonly class PurgeDemoHistory
{
    public function __construct(public int $unitId) {}
}
