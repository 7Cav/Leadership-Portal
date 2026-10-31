<?php

namespace App\Authorization;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * What one viewer may do with one trooper's counseling records (#381, #565), worked out once by {@see CounselingAccess}
 * so a page listing many records asks the ScopeResolver once, not once per record. `$departed`: the trooper has left the
 * Cav, so nothing may be started, shared, withdrawn or answered on them, by anyone; what could be seen still is.
 *
 * **Seeing a record** ({@see seesRecord()}):
 *  - A **draft** (or invited) record: its issuer, their chain (the commanders of the unit it was started through) and
 *    Admins. Never the trooper.
 *  - A shared **DCS**: while **active** (30 days after it was shared) the trooper and their chain as before
 *    ({@see $seesDcs}); while **inactive** (to 90 days) Platoon Leader and up and Admins ({@see $seesInactiveDcs}), not
 *    the trooper and not their Section Leader or ASL; once **expired**, Admins only ({@see $seesExpired}).
 *  - A shared **LOR**: everyone who can see the trooper, permanently ({@see $seesLor}).
 *
 * **Working on a draft** ({@see editsDraft()}): its issuer, their chain and Admins, never on themselves. **Withdrawing**
 * a shared record back to draft ({@see withdraws()}): the same people, before it freezes and before an appeal.
 * **Responding** ({@see responds()}): the trooper, signed in as themselves, once, before {@see CounselingRecord::respondBy()}.
 * **Deciding an appeal** ({@see decidesAppeal()}): the commander of the record's appeal unit, while the appeal is
 * pending. **Overturning** a frozen LOR ({@see overturns()}): the Regimental Executive Officer, as before.
 */
final readonly class CounselingPermissions
{
    /** How long after it is shared a DCS or LOR may still be withdrawn: then it freezes. */
    public const LOCK_HOURS = CounselingRecord::LOCK_HOURS;

    /**
     * @param  array<string, ?int>  $issuingUnits  per type value, the unit the viewer would start a record through (null: none)
     * @param  list<int>  $writeScope  the viewer's write scope (AD-8), narrowed by an active view-as
     * @param  bool  $flagAdmin  an Admin by the `users.is_admin` flag, as themselves: the only one who works on their own
     *                           records (an Admin by billet does not, 2026-10-29)
     * @param  bool  $regimentalXo  the person really signed in holds the Regimental Executive Officer billet, signed in as
     *                              themselves (nobody acting as anyone, no view-as preview): the one who may overturn a
     *                              frozen LOR
     * @param  bool  $seesExpired  an Admin reading as one: the only one who sees an expired DCS
     * @param  bool  $appealsOwn  the viewer is the trooper, signed in as themselves: nobody acting as anyone and no
     *                            view-as preview. The only one who may respond to their records
     * @param  bool  $seesInactiveDcs  Platoon Leader and up over the trooper, or an Admin reading as one: who sees a DCS
     *                                 between 30 and 90 days after it was shared
     * @param  bool  $readsDrafts  an Admin reading as one: sees every draft
     * @param  ?int  $userId  the identity the request runs as: a draft's issuer always sees it
     * @param  ?int  $leadsUnit  the unit the viewer commands (its lead billet), outside a view-as preview: the appeals
     *                           of records whose appeal unit it is are theirs to decide
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
        public bool $seesInactiveDcs = false,
        public bool $readsDrafts = false,
        public ?int $userId = null,
        public ?int $leadsUnit = null,
    ) {}

    /** Whether `$record` is frozen ({@see CounselingRecord::isFrozen()}), on the server's clock. */
    public static function frozen(CounselingRecord $record, ?CarbonImmutable $now = null): bool
    {
        return $record->isFrozen($now);
    }

    public function sees(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->seesDcs : $this->seesLor;
    }

    /** Whether the viewer sees `$record`: a draft as its issuer's chain, a shared DCS by its window, an LOR always. */
    public function seesRecord(CounselingRecord $record): bool
    {
        if ($record->isDraft()) {
            return $this->seesLor && ! $this->self && ($this->readsDrafts || $this->inIssuersChain($record));
        }

        if ($record->type === CounselingType::Lor) {
            return $this->seesLor;
        }

        return match ($record->dcsPhase()) {
            'active' => $this->seesDcs,
            'inactive' => $this->seesInactiveDcs,
            default => $this->seesExpired,
        };
    }

    public function files(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->filesDcs : $this->filesLor;
    }

    /** The unit a new record of `$type` is started through: the lowest command of the viewer's that reaches the trooper. */
    public function issuingUnit(CounselingType $type): ?int
    {
        return $this->issuingUnits[$type->value] ?? null;
    }

    /**
     * Whether the viewer may save, invite on, share or delete the draft `$record`: they see it, may work on the trooper
     * (not departed, not themselves unless an Admin by flag), and are an Admin, its issuer, or command the unit it was
     * started through while still reaching the trooper from above.
     */
    public function editsDraft(CounselingRecord $record): bool
    {
        return $record->isDraft() && ! $record->isOverturned() && $this->worksOn($record);
    }

    /**
     * Whether the viewer may withdraw `$record` back to draft: shared, not frozen, the trooper has not asked to appeal,
     * and the viewer could have worked on it as a draft.
     */
    public function withdraws(CounselingRecord $record): bool
    {
        return $record->isShared()
            && ! $record->isOverturned()
            && ! $record->isAppealed()
            && ! $record->isFrozen()
            && $this->worksOn($record);
    }

    /**
     * Whether the viewer may respond to `$record`: they are the trooper, signed in as themselves, they see it, it is
     * shared, not yet answered, not withdrawn or overturned, and its {@see CounselingRecord::respondBy()} has not passed.
     */
    public function responds(CounselingRecord $record, ?CarbonImmutable $now = null): bool
    {
        return $this->appealsOwn
            && ! $this->departed
            && $record->isShared()
            && $this->seesRecord($record)
            && ! $record->isResponded()
            && ! $record->isOverturned()
            && ($now ?? CarbonImmutable::now())->lessThan($record->respondBy());
    }

    /**
     * Whether the viewer may fill in the fields of `$record` kept with its source PDF (an upload from before #565, or an
     * import, flagged for review or not): its issuer, their chain and Admins, on a trooper who still serves. The source
     * document itself never changes, and the record is not locked by this: it only gains the fields its PDF carries.
     */
    public function reviews(CounselingRecord $record): bool
    {
        return $record->hasSourceDocument() && ! $record->isDraft() && ! $record->isOverturned() && $this->worksOn($record);
    }

    /** Whether the viewer is the appeal authority of `$record` and its appeal is still pending. */
    public function decidesAppeal(CounselingRecord $record, ?CarbonImmutable $now = null): bool
    {
        return $this->leadsUnit !== null
            && ! $this->self
            && $record->appeal_unit_id !== null
            && (int) $record->appeal_unit_id === $this->leadsUnit
            && $this->seesLor
            && $record->isAppealPending($now);
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
     * Whether the viewer may work on `$record` at all: they see it, the trooper serves and is not them (an Admin by flag
     * excepted), and they are an Admin, its issuer, or in its issuer's chain.
     */
    private function worksOn(CounselingRecord $record): bool
    {
        if ($this->departed || ! $this->seesRecord($record) || $this->self && ! $this->flagAdmin) {
            return false;
        }

        return $this->admin || $this->inIssuersChain($record);
    }

    /**
     * The issuer, or a commander of the unit the record was started through who still reaches the trooper from above
     * (that unit's subtree is in their write scope, so the issuer and the chain above them qualify).
     */
    private function inIssuersChain(CounselingRecord $record): bool
    {
        if ($this->userId !== null && (int) $record->issued_by === $this->userId) {
            return true;
        }

        return $this->issuingUnit(CounselingType::Dcs) !== null
            && $record->issuer_unit_id !== null
            && in_array((int) $record->issuer_unit_id, $this->writeScope, true);
    }
}
