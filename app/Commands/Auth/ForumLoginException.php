<?php

namespace App\Commands\Auth;

use RuntimeException;

/**
 * The forum login handshake failed (token exchange, profile fetch, or a malformed
 * payload). Lives in the CommandHandler layer so the controller may catch it
 * (Controller → CommandHandler is allowed; Controller → Integration is not) and
 * convert it into the "back to login, no partial session" response (AC #5).
 */
class ForumLoginException extends RuntimeException {}
