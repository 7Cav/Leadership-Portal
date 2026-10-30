<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;
use App\Models\Trooper;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not file, correct or delete this counseling record (#381, AD-8): their command does not reach the
 * trooper at the echelon the record needs, the record was filed through a chain they are not in, or the trooper has
 * left the Cav (no longer serving, `TrooperStatus::serving()`), which makes their records read-only for everyone, or the
 * record is past its 72-hour appeal window (only an LOR may then be overturned, by the Regimental Executive Officer). Enforced in the
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

    /** A record past its 72-hour appeal window: nobody corrects or deletes it, Admins included. Only an LOR is overturned. */
    public static function frozen(CounselingType $type = CounselingType::Lor): self
    {
        return new self($type === CounselingType::Lor
            ? 'This LOR was filed more than 72 hours ago, so it can no longer be changed. The Regimental Executive Officer can overturn it.'
            : 'This DCS was filed more than 72 hours ago, so it can no longer be changed.');
    }

    /** The trooper's appeal, or comment or dispute, comes only within the 72 hours after the record was filed. */
    public static function appealClosed(CounselingType $type): self
    {
        return new self($type === CounselingType::Lor
            ? 'This LOR was filed more than 72 hours ago, so it can no longer be appealed.'
            : 'This DCS was filed more than 72 hours ago, so it can no longer be commented on or disputed.');
    }

    public static function alreadyAppealed(CounselingType $type): self
    {
        return new self($type === CounselingType::Lor
            ? 'You already appealed this LOR.'
            : 'You already commented on or disputed this DCS.');
    }

    public static function appeal(): self
    {
        return new self('Only the trooper, signed in as themselves, can appeal their own record.');
    }

    public static function overturned(): self
    {
        return new self('This LOR was overturned, so it can no longer be changed.');
    }

    public static function onlyLor(): self
    {
        return new self('Only a Letter of Reprimand can be overturned.');
    }

    public static function alreadyOverturned(): self
    {
        return new self('This LOR is already overturned.');
    }

    public static function notFrozen(): self
    {
        return new self('This LOR is still in its 72-hour appeal window. The issuer\'s chain of command can correct or delete it.');
    }

    public static function overturnOwn(): self
    {
        return new self('You cannot overturn an LOR filed on yourself.');
    }

    public static function overturn(): self
    {
        return new self('Only the Regimental Executive Officer, signed in as themselves, can overturn an LOR.');
    }
}
