<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Where a viewer's view-as target lives between requests (Story 1.13): the server-side session,
 * so there is no new table and the browser holds nothing it could edit. The stored value is
 * bound to the user who set it, so it can never apply to anyone else's scope.
 *
 * This only remembers the *request*. Whether it is honoured is {@see ScopeResolver}'s call, made
 * again on every read against the viewer's current real authority (AD-9).
 */
class ViewAs
{
    private const KEY = 'view_as';

    public function __construct(private readonly Session $session) {}

    public function enter(User $viewer, ViewAsTarget $target): void
    {
        $this->session->put(self::KEY, [
            'viewer' => $viewer->id,
            'kind' => $target->kind->value,
            'id' => $target->id,
        ]);
    }

    public function exit(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * The target `$viewer` stored, or null when there is none, it was set by someone else, or the
     * stored value is not one this class wrote.
     */
    public function targetFor(User $viewer): ?ViewAsTarget
    {
        $stored = $this->session->get(self::KEY);

        if (! is_array($stored) || ($stored['viewer'] ?? null) !== $viewer->id || ! is_int($stored['id'] ?? null)) {
            return null;
        }

        $kind = ViewAsKind::tryFrom((string) ($stored['kind'] ?? ''));

        return $kind ? new ViewAsTarget($kind, $stored['id']) : null;
    }
}
