<?php

namespace App\Authorization;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * What one viewer may do with one trooper's counseling records (#381), worked out once by {@see CounselingAccess} so a
 * page listing many records asks the ScopeResolver once, not once per record. `$departed`: the trooper has left the Cav,
 * so nothing may be filed on them, corrected or deleted, by anyone; what could be seen still is.
 *
 * An LOR is **frozen** once its appeal window has passed, 72 hours after it was filed (S6, 2026-10-29; the wiki gives the
 * trooper 24 hours to say they appeal and the appeal 72 hours to finish, with no further appeal). Until then whoever may
 * change it still can (a typo, the wrong trooper, an upheld appeal). From then on nobody corrects or deletes it, Admins
 * included, by flag or by billet: only the Regimental Executive Officer may overturn it ({@see overturns()}), which
 * keeps it on file. A DCS never freezes.
 */
final readonly class CounselingPermissions
{
    /** How long after it is filed an LOR may still be corrected or deleted: its appeal window. */
    public const APPEAL_HOURS = 72;

    /**
     * @param  array<string, ?int>  $issuingUnits  per type value, the unit the viewer would file through (null: none)
     * @param  list<int>  $writeScope  the viewer's write scope (AD-8), narrowed by an active view-as
     * @param  bool  $flagAdmin  an Admin by the `users.is_admin` flag, as themselves: the only one who changes their own
     *                           records (an Admin by billet does not, 2026-10-29)
     * @param  bool  $regimentalXo  the viewer holds the Regimental Executive Officer billet, as themselves or as someone
     *                              acts as them, and no view-as preview is active: the one who may overturn a frozen LOR
     */
    public function __construct(
        public bool $seesDcs,
        public bool $seesLor,
        public bool $filesDcs,
        public bool $filesLor,
        public bool $admin,
        public bool $self,
        public bool $departed,
        public array $issuingUnits,
        public array $writeScope,
        public bool $flagAdmin,
        public bool $regimentalXo,
    ) {}

    /**
     * Whether `$record` is a frozen LOR: filed {@see APPEAL_HOURS} hours ago or more, so its appeal window has passed
     * (exactly 72 hours after filing it is frozen). Judged from when it was filed (`created_at`), never from the date it
     * carries, on the server's clock.
     */
    public static function frozen(CounselingRecord $record, ?CarbonImmutable $now = null): bool
    {
        if ($record->type !== CounselingType::Lor || $record->created_at === null) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        return $now->greaterThanOrEqualTo(CarbonImmutable::instance($record->created_at)->addHours(self::APPEAL_HOURS));
    }

    public function sees(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->seesDcs : $this->seesLor;
    }

    public function files(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->filesDcs : $this->filesLor;
    }

    /** The unit a new record of `$type` is filed through: the lowest command of the viewer's that reaches the trooper. */
    public function issuingUnit(CounselingType $type): ?int
    {
        return $this->issuingUnits[$type->value] ?? null;
    }

    /**
     * Whether the viewer may correct or delete `$record`: they must see it, and be an Admin or command the unit it was
     * filed through (that unit's subtree is in their write scope, so the issuer and the chain above them qualify).
     * A leader must also still reach the trooper from above: one the trooper now commands, or who now shares the
     * trooper's command, no longer changes what was filed before. Nobody changes the records of a departed trooper.
     * Nobody changes their own, except an Admin by the flag: an Admin by billet holds it because of Cav's billet list,
     * not because someone chose them, so their own records stay out of their reach like anyone's. Nobody changes a
     * frozen or overturned LOR ({@see frozen()}), Admins included.
     */
    public function changes(CounselingRecord $record): bool
    {
        if ($record->isOverturned() || self::frozen($record)) {
            return false;
        }

        if ($this->departed || ! $this->sees($record->type) || $this->self && ! $this->flagAdmin) {
            return false;
        }

        if ($this->admin) {
            return true;
        }

        return $this->issuingUnit(CounselingType::Dcs) !== null
            && $record->issuer_unit_id !== null
            && in_array((int) $record->issuer_unit_id, $this->writeScope, true);
    }

    /**
     * Whether the viewer may overturn `$record` (S6, 2026-10-29): a frozen LOR not yet overturned, seen by the Regimental
     * Executive Officer, never on themselves (the person really acting included). A departed trooper's LOR may be
     * overturned too: it corrects the record, it does not add to it.
     */
    public function overturns(CounselingRecord $record): bool
    {
        return $this->regimentalXo
            && ! $this->self
            && $record->type === CounselingType::Lor
            && $this->seesLor
            && ! $record->isOverturned()
            && self::frozen($record);
    }
}
