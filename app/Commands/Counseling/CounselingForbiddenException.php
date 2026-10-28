<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;
use App\Models\Trooper;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not file, correct or delete this counseling record (#381, AD-8): their command does not reach the
 * trooper at the echelon the record needs, the record was filed through a chain they are not in, or the trooper has
 * left the Cav (no longer serving, `TrooperStatus::serving()`), which makes their records read-only for everyone. Enforced in the
 * handlers whatever the page offered. An `AuthorizationException`, so an HTTP caller that lets it propagate answers 403.
 */
class CounselingForbiddenException extends AuthorizationException
{
    public static function file(CounselingType $type): self
    {
        return new self($type === CounselingType::Lor
            ? 'Only command from platoon level up can file a Letter of Reprimand on this trooper.'
            : 'Only a leader whose command includes this trooper can file a Developmental Counseling Statement on them.');
    }

    public static function departed(Trooper $trooper): self
    {
        return new self("{$trooper->username} is no longer serving, so their counseling records are read-only.");
    }

    public static function change(): self
    {
        return new self('Only the issuer\'s chain of command or an Admin can change this record.');
    }
}
