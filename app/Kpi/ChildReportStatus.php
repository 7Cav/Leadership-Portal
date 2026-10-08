<?php

namespace App\Kpi;

/**
 * Where one child unit's report for the week stands, as a roll-up sees it (5.7, AD-13). Only
 * `Sent` contributes to the parent; `Draft` and `NotStarted` hold up the chain.
 */
enum ChildReportStatus: string
{
    case Sent = 'sent';
    case Draft = 'draft';
    case NotStarted = 'not_started';
}
