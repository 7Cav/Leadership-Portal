<?php

namespace App\Commands\Units;

use DomainException;

/**
 * A billet change on Org structure the domain rules refuse: a billet the import has not recorded, one already in the
 * unit picked, one an Admin placed elsewhere (until the move is confirmed), a rename with nothing to confirm, a placed
 * billet left unplaced, or a billet with no placement to undo.
 * Domain rules, not request shape, so the controller turns this into a form error.
 */
class InvalidBilletChangeException extends DomainException
{
    /** Whether the Admin may go ahead by confirming, as for a move of a billet placed elsewhere. */
    public bool $needsConfirmation = false;

    public static function unknown(): self
    {
        return new self('The Cav has not listed this billet yet. Run Import from Cav roster, then try again.');
    }

    public static function noSuchUnit(): self
    {
        return new self('That unit no longer exists. Reload the page and choose another.');
    }

    public static function alreadyThere(string $unit): self
    {
        return new self("This billet is already placed in {$unit}.");
    }

    /**
     * An Admin already placed this billet, or another of its name, in another unit: confirming moves them all here.
     *
     * @param  list<string>  $units
     */
    public static function placedElsewhere(string $title, array $units): self
    {
        $e = new self(sprintf('%s is already placed in %s. Move it here instead?', $title, implode(' and ', $units)));
        $e->needsConfirmation = true;

        return $e;
    }

    public static function notRenamed(): self
    {
        return new self('This billet has no new name to confirm. Reload the page.');
    }

    public static function notAssigned(): self
    {
        return new self('This billet follows the import rules already. There is nothing to remove.');
    }

    public static function alreadyPlaced(): self
    {
        return new self('This billet is placed in a unit already, so it cannot be left unplaced. Move it instead.');
    }
}
