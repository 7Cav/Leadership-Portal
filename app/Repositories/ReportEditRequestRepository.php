<?php

namespace App\Repositories;

use App\Models\ReportEditRequest;
use Carbon\CarbonInterface;

/**
 * Requests to edit a locked report ({@see ReportEditRequest}). A weekly report is keyed by its unit (`$trooperId`
 * null), a staff report by its staff member (`$unitId` is their HQ, kept for who decides).
 */
interface ReportEditRequestRepository
{
    public function find(int $id): ?ReportEditRequest;

    /** The newest request for the report, pending or decided, with its requester and decider loaded. */
    public function latestFor(int $unitId, ?int $trooperId, CarbonInterface $weekStart): ?ReportEditRequest;

    /** Whether the report has a request still waiting on a decision. */
    public function hasPending(int $unitId, ?int $trooperId, CarbonInterface $weekStart): bool;

    /** Store a new pending request. Call inside the audited transaction. */
    public function create(int $unitId, ?int $trooperId, CarbonInterface $weekStart, int $requestedBy, string $reason): ReportEditRequest;

    /**
     * Decide a pending request under a row lock: `approved` with the time the report stays unlocked until, or
     * `denied`. Null when it is no longer pending (someone else decided it first). Call inside the audited transaction.
     */
    public function decide(int $id, bool $approve, int $decidedBy, ?string $note, ?CarbonInterface $unlockedUntil): ?ReportEditRequest;
}
