<?php

namespace App\Models;

/**
 * An `admin_billets` row's state (policy, 2026-10-28). The values mirror the `admin_billets.status` DB enum by
 * hand; change both together. Only `Approved` makes the billet's holder an Admin, and only while the billet still
 * carries the names that were confirmed.
 */
enum AdminBilletStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
