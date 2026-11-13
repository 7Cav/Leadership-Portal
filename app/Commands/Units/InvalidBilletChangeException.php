<?php

namespace App\Commands\Units;

use DomainException;

/**
 * A billet change on Org structure the domain rules refuse: a billet the import has not recorded, one already in the
 * unit picked, one an Admin placed elsewhere (until the move is confirmed), a section billet placed in a company or
 * higher (until that is confirmed), a rename with nothing to confirm, a placed
 * billet left unplaced, a billet with no placement to undo, or an acknowledgement of a billet in two units that does not
 * fit what is placed now.
 * Domain rules, not request shape, so the controller turns this into a form error.
 */
class InvalidBilletChangeException extends DomainException
{
    /**
     * What the Admin may confirm to go ahead, or null when confirming does not help: `move` for a billet placed
     * elsewhere, `section` for a section billet placed in a company or higher.
     */
    public ?string $confirmation = null;

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
        $e->confirmation = 'move';

        return $e;
    }

    /**
     * A section billet (one whose title names a section) placed in a company, battalion, the regiment or a group:
     * confirming places it there anyway. `$unitKind` names what the unit is, such as `a company`.
     */
    public static function sectionBilletAbove(string $title, string $unit, string $unitKind): self
    {
        $e = new self("{$title} is a section billet. Place it in {$unit}, {$unitKind}, anyway?");
        $e->confirmation = 'section';

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

    public static function noConflict(): self
    {
        return new self('This billet is not in two units at once any more. Reload the page.');
    }

    public static function alreadyAcknowledged(): self
    {
        return new self('This billet in two units is acknowledged already.');
    }

    public static function notAcknowledged(): self
    {
        return new self('This billet in two units has no acknowledgement to undo. Reload the page.');
    }

    public static function alreadyPlaced(): self
    {
        return new self('This billet is placed in a unit already, so it cannot be left unplaced. Move it instead.');
    }
}
