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
 * A DCS or LOR is **frozen** 72 hours after it was filed ({@see frozen()}). Until then the issuer's chain (and Admins)
 * may still correct or delete it (a typo, the wrong trooper, an upheld appeal), and the trooper may file one appeal on
 * an LOR, or one comment or dispute on a DCS ({@see appeals()}). From then on nobody corrects or deletes it, Admins
 * included, by flag or by billet. Only an LOR may then be overturned, by the Regimental Executive Officer
 * ({@see overturns()}), which keeps it on file; a DCS has no overturn. A frozen DCS still expires after 90 days.
 *
 * An **expired DCS** ({@see CounselingRecord::isExpired()}, 90 days after it was counseled) is seen by Admins only
 * ({@see $seesExpired}): to anyone else, the trooper themselves included, it is as if it did not exist. Leaders never
 * correct or delete it; Admins keep their rights over it.
 */
final readonly class CounselingPermissions
{
    /** How long after it is filed a DCS or LOR may still be corrected, deleted or appealed: its appeal window. */
    public const APPEAL_HOURS = CounselingRecord::APPEAL_HOURS;

    /**
     * @param  array<string, ?int>  $issuingUnits  per type value, the unit the viewer would file through (null: none)
     * @param  list<int>  $writeScope  the viewer's write scope (AD-8), narrowed by an active view-as
     * @param  bool  $flagAdmin  an Admin by the `users.is_admin` flag, as themselves: the only one who changes their own
     *                           records (an Admin by billet does not, 2026-10-29)
     * @param  bool  $regimentalXo  the person really signed in holds the Regimental Executive Officer billet, signed in as
     *                              themselves (nobody acting as anyone, no view-as preview): the one who may overturn a
     *                              frozen LOR
     * @param  bool  $seesExpired  an Admin reading as one: the only one who sees an expired DCS
     * @param  bool  $appealsOwn  the viewer is the trooper, signed in as themselves: nobody acting as anyone and no
     *                            view-as preview. The only one who may appeal their records
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
        public bool $seesExpired = false,
        public bool $appealsOwn = false,
    ) {}

    /**
     * Whether `$record` is frozen: filed {@see APPEAL_HOURS} hours ago or more, a DCS or an LOR (exactly 72 hours after
     * filing it is frozen). Judged from when it was filed (`created_at`), never from the date it carries, on the
     * server's clock.
     */
    public static function frozen(CounselingRecord $record, ?CarbonImmutable $now = null): bool
    {
        $freezesAt = $record->freezesAt();
        if ($freezesAt === null) {
            return false;
        }

        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($freezesAt);
    }

    public function sees(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->seesDcs : $this->seesLor;
    }

    /** Whether the viewer sees `$record`: its type, and an expired DCS only as an Admin. */
    public function seesRecord(CounselingRecord $record): bool
    {
        return $this->sees($record->type) && ($this->seesExpired || ! $record->isExpired());
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
     * frozen record ({@see frozen()}) or an overturned LOR, Admins included. Only an Admin changes an expired DCS.
     */
    public function changes(CounselingRecord $record): bool
    {
        if ($record->isOverturned() || self::frozen($record)) {
            return false;
        }

        if ($this->departed || ! $this->seesRecord($record) || $this->self && ! $this->flagAdmin) {
            return false;
        }

        if ($this->admin) {
            return true;
        }

        if ($record->isExpired()) {
            return false;
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

    /**
     * Whether the viewer may file their appeal (LOR) or comment or dispute (DCS) on `$record`: they are the trooper,
     * signed in as themselves ({@see $appealsOwn}), they see the record, it is not frozen yet, not overturned, and has no
     * appeal yet (one per record). Nothing is appealed on a trooper who has left the Cav, whose records are read-only.
     */
    public function appeals(CounselingRecord $record): bool
    {
        return $this->appealsOwn
            && ! $this->departed
            && $this->seesRecord($record)
            && ! $record->isAppealed()
            && ! $record->isOverturned()
            && ! self::frozen($record);
    }
}
