<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;
use App\Models\Trooper;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not take this step on this counseling record (#381, #565, AD-8): their command does not reach the
 * trooper at the echelon the record needs, the record was started through a chain they are not in, the trooper has left
 * the Cav (no longer serving, `TrooperStatus::serving()`), which makes their records read-only for everyone, the record
 * is not at the step this needs, or it is locked (only an LOR may then be overturned, by the Regimental Executive
 * Officer). Enforced in the handlers whatever the page offered. An `AuthorizationException`, so an HTTP caller that lets
 * it propagate answers 403.
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

    /** Only the issuer, their chain and Admins work on a draft, and never on themselves. */
    public static function draft(): self
    {
        return new self('Only the issuer\'s chain of command or an Admin can work on this draft.');
    }

    /** The step needs a draft, and this record has been shared. */
    public static function notDraft(): self
    {
        return new self('This record has been shared, so its draft can no longer be changed. Withdraw it first.');
    }

    /** An invite goes out once, from a draft. */
    public static function alreadyInvited(): self
    {
        return new self('The meeting invite for this record was already sent.');
    }

    /** A record shared 72 hours ago or more, or an LOR whose appeal was decided: nobody withdraws it. */
    public static function frozen(CounselingType $type = CounselingType::Lor): self
    {
        return new self($type === CounselingType::Lor
            ? 'This LOR is locked, so it can no longer be withdrawn. The Regimental Executive Officer can overturn it.'
            : 'This DCS is locked, so it can no longer be withdrawn.');
    }

    public static function withdraw(): self
    {
        return new self('Only the issuer\'s chain of command or an Admin can withdraw this record, before it locks and before an appeal.');
    }

    public static function respond(): self
    {
        return new self('Only the trooper, signed in as themselves, can respond to their own record.');
    }

    public static function alreadyResponded(): self
    {
        return new self('You already responded to this record.');
    }

    public static function responseClosed(CounselingType $type): self
    {
        return new self($type === CounselingType::Lor
            ? 'The 24 hours to answer this LOR have passed, so it stands as written.'
            : 'This DCS is locked, so it can no longer be answered.');
    }

    public static function decide(): self
    {
        return new self('Only the commander one level above the issuing unit decides this appeal, while it is pending.');
    }

    public static function review(): self
    {
        return new self('Only the issuer\'s chain of command or an Admin can fill in this record.');
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
        return new self('This LOR is not locked yet. The issuer\'s chain of command can still withdraw it.');
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
