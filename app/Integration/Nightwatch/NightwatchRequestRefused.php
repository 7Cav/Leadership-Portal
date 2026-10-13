<?php

namespace App\Integration\Nightwatch;

use LogicException;

/**
 * A request was refused before it left the app because it was not a GET under the configured
 * `/api/public/stats` base. This is a programming error (read-only by construction, proposal
 * §9.4), so it is a LogicException and is never disguised as an outage.
 */
class NightwatchRequestRefused extends LogicException {}
