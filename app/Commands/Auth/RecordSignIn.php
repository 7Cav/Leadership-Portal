<?php

namespace App\Commands\Auth;

use App\Models\User;
use App\Support\Audit\AuditEnvelope;

/**
 * Sign-ins, refused sign-ins and sign-outs in the audit log (AD-16), each against the user it was for: `auth.signed_in`,
 * `auth.refused` and `auth.signed_out`. A sign-in names the address it was started from and the page it was headed
 * to, so an entry shows how someone got in. A failed handshake (cancelled, bad state, forum down) names no user and
 * stays in the application log only.
 */
class RecordSignIn
{
    public function __construct(private readonly AuditEnvelope $audit) {}

    /**
     * @param  string  $via  where the sign-in was started, scheme and host (`https://portal.example`)
     * @param  ?string  $headedTo  the page they were sent to sign in from, if any
     */
    public function signedIn(User $user, string $via, ?string $headedTo): void
    {
        $this->audit->recordEvent($user->id, 'auth.signed_in', $user, $this->notes($via, $headedTo));
    }

    /**
     * @param  string  $why  what the refusal told them, in words
     */
    public function refused(User $user, string $why, string $via, ?string $headedTo): void
    {
        $this->audit->recordEvent($user->id, 'auth.refused', $user, [$why, ...$this->notes($via, $headedTo)]);
    }

    /**
     * `$actor` is who really signed out: the Admin, when they were acting as `$user`.
     */
    public function signedOut(User $actor): void
    {
        $this->audit->recordEvent($actor->id, 'auth.signed_out', $actor);
    }

    /** @return list<string> */
    private function notes(string $via, ?string $headedTo): array
    {
        return array_values(array_filter([
            "Via {$via}",
            $headedTo !== null ? "Headed to {$headedTo}" : null,
        ]));
    }
}
