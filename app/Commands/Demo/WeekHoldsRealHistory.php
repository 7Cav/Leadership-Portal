<?php

namespace App\Commands\Demo;

use RuntimeException;

/**
 * Internal to {@see GenerateDemoHistoryHandler}: real history appeared in a week between the
 * up-front check and that week's transaction. Thrown from inside the audited mutation so the week
 * rolls back with no audit entry; the handler catches it and reports the week as skipped.
 */
final class WeekHoldsRealHistory extends RuntimeException {}
