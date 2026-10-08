<?php

namespace App\Models;

/**
 * A `weekly_reports` row's lifecycle (AD-12). The values mirror the `weekly_reports.status` DB
 * enum by hand — change both together; `WeeklyReportSchemaTest` checks every case here is
 * accepted by the DB. `Sent` is terminal: the snapshot is frozen and never recomputed.
 */
enum WeeklyReportStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
}
