<?php

namespace App\Repositories;

use App\Models\AppealOutcome;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `counseling_records` (AD-2, #381, #565). Portal-owned (AD-22): the audited `app/Commands/Counseling`
 * Commands are its one writer. A source document itself is on the private disk, behind {@see CounselingDocuments}.
 *
 * Every write that changes a record re-reads it under a lock in the caller's transaction and checks it is still in the
 * step the write expects, so two writes at once cannot both land: it answers null when the record is gone or has moved
 * on. Every write raises `version` by one.
 */
interface CounselingRecordRepository
{
    /** Start a draft. Every column is named here, none taken from a request as a whole. */
    public function startDraft(
        int $trooperId,
        CounselingType $type,
        CounselingKind $kind,
        int $issuedBy,
        ?int $issuedByAdmin,
        ?int $issuedAsUnit,
        ?int $issuerUnitId,
    ): CounselingRecord;

    /**
     * Save a draft as it stands: its type and kind, the Date of Letter, the leader's fields and whether the meeting
     * took place. Null when it is gone or no longer a draft.
     *
     * @param  array<string, mixed>  $formData  the leader's fields, as the counseling form names them
     */
    public function saveDraft(CounselingRecord $record, CounselingType $type, CounselingKind $kind, ?CarbonImmutable $counseledOn, array $formData, bool $meetingConfirmed): ?CounselingRecord;

    /**
     * Mark a draft invited: the meeting windows and when the invite went. Null when it is gone or no longer a draft.
     *
     * @param  list<string>  $windows
     */
    public function invite(CounselingRecord $record, array $windows, CarbonImmutable $at): ?CounselingRecord;

    /**
     * Share a draft: Section 1 as it stands (`$snapshot`), when, and the unit whose commander would decide an appeal.
     * Null when it is gone or no longer a draft.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function share(CounselingRecord $record, array $snapshot, CarbonImmutable $at, ?int $appealUnitId): ?CounselingRecord;

    /**
     * Take a shared record back to draft, with when and why; the snapshot and any response are cleared. Null when it is
     * gone, not shared, or the trooper has asked to appeal.
     */
    public function withdraw(CounselingRecord $record, CarbonImmutable $at, string $reason): ?CounselingRecord;

    /**
     * The trooper's one response: their fields beside the leader's, and on an LOR they appeal, when and why. Null when
     * it is gone, not shared, or already answered.
     *
     * @param  array<string, mixed>  $trooperFields  the trooper's fields, as the counseling response names them
     */
    public function respond(CounselingRecord $record, CarbonImmutable $at, int $by, array $trooperFields, ?string $appealStatement): ?CounselingRecord;

    /**
     * The appeal authority's decision: when, by whom, the outcome and why, their rank, name and billet then, and for an
     * amendment the leader's fields as amended, with the consequence as first written kept in `appeal_original`. Null
     * when it is gone or its appeal is not pending.
     *
     * @param  array{rank: string, name: string, billet: string}  $authority
     * @param  array<string, mixed>|null  $amended  the consequence fields as amended, or null when not amended
     */
    public function decideAppeal(CounselingRecord $record, CarbonImmutable $at, int $by, ?int $byAdmin, AppealOutcome $outcome, string $reason, array $authority, ?array $amended): ?CounselingRecord;

    /**
     * Mark an LOR overturned (S6, 2026-10-29): when, by whom (with the one acting as them, and the billet held, as on a
     * filing) and why. Null when the record is gone or already overturned. Nothing else on the record changes.
     */
    public function overturn(CounselingRecord $record, CarbonImmutable $at, int $by, ?int $byAdmin, ?int $asUnit, string $reason): ?CounselingRecord;

    /**
     * Delete the record, so the model handed back (and digested by the audit log) is the row as it was deleted. Null
     * when it is already gone.
     */
    public function delete(CounselingRecord $record): ?CounselingRecord;

    /**
     * Store a record brought in by the counseling import (#565): shared and acknowledged on its date, with its source
     * document, the key that keeps a re-run from duplicating it, and whether its fields still need someone to fill them in.
     *
     * @param  array<string, mixed>|null  $formData
     */
    public function createImported(
        int $trooperId,
        CounselingType $type,
        CounselingKind $kind,
        CarbonImmutable $counseledOn,
        int $issuedBy,
        ?int $issuerUnitId,
        StoredDocument $document,
        string $importKey,
        bool $needsReview,
        ?array $formData,
    ): CounselingRecord;

    /** Which of `$keys` an import already brought in. @param list<string> $keys @return list<string> */
    public function importedKeys(array $keys): array;

    /**
     * Fill in the fields of a record kept with a source document (an upload or an import), and clear its review flag.
     * Null when it is gone.
     *
     * @param  array<string, mixed>  $formData
     */
    public function review(CounselingRecord $record, CounselingType $type, CounselingKind $kind, CarbonImmutable $counseledOn, array $formData): ?CounselingRecord;

    /** The record with its trooper loaded, or null. */
    public function find(int $id): ?CounselingRecord;

    /**
     * A trooper's records, drafts first, then newest first (by date, then by id), each with whoever started, responded
     * to, decided and overturned it loaded, so the page names them without a query per record.
     *
     * @return Collection<int, CounselingRecord>
     */
    public function forTrooper(int $trooperId): Collection;

    /**
     * The LORs whose appeal `$unitId`'s commander decides, appealed ones not yet decided first (by when asked), then the
     * decided ones of the last `$decidedDays` days, each with its trooper and issuer loaded.
     *
     * @return Collection<int, CounselingRecord>
     */
    public function appealsFor(int $unitId, int $decidedDays = 90): Collection;

    /** How many appeals `$unitId`'s commander has to decide now: asked for, not decided, not yet lapsed. */
    public function pendingAppealCount(int $unitId, CarbonImmutable $now): int;
}
