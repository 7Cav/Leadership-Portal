<?php

namespace App\Authorization;

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

/**
 * Who counts as staff, and who may enter or remove the section practices a staff member attended (#448, AD-8).
 *
 * Staff are the troopers placed on an HQ node (platoon, company, battalion or regimental staff, decided 2026-10-24) or in
 * a staff department (`UnitKind::Staff`). Section troopers have a rollcall of their own, and ELOA, Reservists and New
 * Recruits are groups but not staff, so none of them is.
 *
 * Entering one: the staff member themselves (a self-report, which the record names), anyone whose command reaches the
 * unit they are placed in ({@see ScopeResolver::commandsUnit()}: their staff leader and the chain above, by billet,
 * acting lead or a held billet, never a staff grant), and Admins. Only while the trooper serves and is staff. Removing
 * one: the same people, judged on the unit the trooper is placed in now, so an entry stays removable after they move.
 * A view-as preview is refused by the `ReadOnlyWhileViewingAs` middleware before any of this is asked.
 */
class StaffPracticeAccess
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    /** Whether a trooper placed on `$unit` is staff: an HQ node of the line, or a staff department. */
    public static function isStaffUnit(Unit $unit): bool
    {
        return $unit->type === UnitType::Group
            ? $unit->kind === UnitKind::Staff
            : $unit->type !== UnitType::Section;
    }

    /** The HQ node or staff department that makes the trooper staff, or null when they are not staff. */
    public function staffUnit(Trooper $trooper): ?Unit
    {
        $unit = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);

        return $unit !== null && self::isStaffUnit($unit) ? $unit : null;
    }

    /** Whether `$user` may enter a practice `$trooper` attended: a serving staff member, by themselves or their leader. */
    public function mayRecord(User $user, Trooper $trooper): bool
    {
        $unit = $this->staffUnit($trooper);

        return $unit !== null && $trooper->status->isServing() && $this->speaksFor($user, $trooper, $unit);
    }

    /** Whether `$user` may remove one of `$trooper`'s entries: the same people, wherever the trooper is placed now. */
    public function mayRemove(User $user, Trooper $trooper): bool
    {
        $unit = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);

        return $user->is_admin || $this->isSelf($user, $trooper) || ($unit !== null && $this->scope->commandsUnit($user, $unit));
    }

    /** Whether the request runs as the trooper themselves: their own entry is a self-report. */
    public function isSelf(User $user, Trooper $trooper): bool
    {
        return $user->cav_id !== null && (int) $user->cav_id === (int) $trooper->cav_id;
    }

    private function speaksFor(User $user, Trooper $trooper, Unit $unit): bool
    {
        return $this->isSelf($user, $trooper) || $this->scope->commandsUnit($user, $unit);
    }
}
