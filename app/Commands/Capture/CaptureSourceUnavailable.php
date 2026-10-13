<?php

namespace App\Commands\Capture;

use RuntimeException;

/**
 * A capture source could not say who was present. Each seam raises its own typed failure; the
 * source map ({@see CaptureSources}) translates it into this one, so the handler never names a
 * seam and a new source (Nightwatch, DCS) needs no handler change.
 */
class CaptureSourceUnavailable extends RuntimeException {}
