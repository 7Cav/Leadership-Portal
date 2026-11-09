<?php

namespace App\Tutorial;

use Illuminate\Contracts\Session\Session;

/**
 * The tutorial flag in the person's session: on or off, who it belongs to, who they play and where they came from.
 * Nothing else about the tutorial is kept in the session. The practice data is in their own practice copy.
 */
final class TutorialSession
{
    private const KEY = 'tutorial';

    public function __construct(private readonly Session $session) {}

    public function start(int $userId, TutorialPersona $persona, string $returnTo): void
    {
        $this->session->put(self::KEY, ['user' => $userId, 'persona' => $persona->toArray(), 'return' => $returnTo]);
    }

    /** Turn it off. Returns the page the person started from. */
    public function stop(): ?string
    {
        $return = $this->session->get(self::KEY.'.return');
        $this->session->forget(self::KEY);

        return is_string($return) ? $return : null;
    }

    /** Whether the tutorial is on for this user. A flag left by someone else on this session never counts. */
    public function activeFor(int $userId): bool
    {
        return (int) $this->session->get(self::KEY.'.user') === $userId && is_array($this->session->get(self::KEY.'.persona'));
    }

    public function persona(): ?TutorialPersona
    {
        $data = $this->session->get(self::KEY.'.persona');

        return is_array($data) ? TutorialPersona::fromArray($data) : null;
    }

    public function returnTo(): ?string
    {
        $return = $this->session->get(self::KEY.'.return');

        return is_string($return) ? $return : null;
    }
}
