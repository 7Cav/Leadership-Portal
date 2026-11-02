<?php

namespace App\Commands\GameIdentities;

use RuntimeException;

/**
 * A shared Steam or Epic account could not be linked to every game (#174). Nothing was written. The message is shown
 * to the leader and names what to fix.
 */
class SharedAccountException extends RuntimeException
{
    public static function notShared(string $kind): self
    {
        return new self("A {$kind} is entered per game. Only a Steam or Epic ID can be linked to every game.");
    }

    /** @param  list<string>  $owners  each as "SGT Ortega.M on Hell Let Loose" */
    public static function linkedToOthers(array $owners): self
    {
        return new self('Already linked to '.self::join($owners).'. Nothing was linked.');
    }

    /** @param  list<string>  $games */
    public static function differentId(string $kind, array $games): self
    {
        return new self('This trooper already has a different '.$kind.' on '.self::join($games).'. Change or remove '.(count($games) === 1 ? 'it' : 'those').' first. Nothing was linked.');
    }

    /** @param  list<string>  $items */
    private static function join(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)).' and '.end($items) : ($items[0] ?? '');
    }
}
