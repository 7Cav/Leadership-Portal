<?php

namespace App\Commands\Fireteams;

use RuntimeException;

/**
 * A fireteam change no leader may make. Thrown before anything is written, in plain words the rollcall screen shows as
 * it is (the controller answers 422 with the message).
 */
class InvalidFireteamException extends RuntimeException
{
    public static function notASection(int $unitId): self
    {
        return new self('This unit is not a section, so it has no fireteams.');
    }

    public static function notACompany(int $unitId): self
    {
        return new self('This unit is not a company, so it sets no fireteam policy for sections.');
    }

    public static function notPlaced(int $trooperId): self
    {
        return new self('That trooper is not placed in this section. Reload the page to see the section as it is now.');
    }

    public static function leadsSection(string $name, string $role): self
    {
        return new self("{$name} is the section's {$role} and is always listed first, so they cannot be a fireteam member, and lead a fireteam only where the company allows it.");
    }

    public static function memberNeedsLeader(): self
    {
        return new self('Choose the fireteam leader this trooper follows.');
    }

    public static function notALeader(int $leaderTrooperId): self
    {
        return new self('The chosen leader is not a fireteam leader in this section. Mark them as FTL first.');
    }

    public static function ownFireteam(): self
    {
        return new self('A trooper cannot be a member of their own fireteam.');
    }

    public static function leadsFireteam(string $name): self
    {
        return new self("{$name} leads a fireteam. Unmark them as FTL before putting them in another fireteam.");
    }

    public static function setByCompany(string $what): self
    {
        return new self("The company has set {$what} for every section, so a section cannot change it.");
    }

    public static function notAPolicy(string $what): self
    {
        return new self("A company can leave {$what} to its sections or enforce it, nothing else.");
    }
}
