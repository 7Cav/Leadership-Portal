<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CounselingRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Developmental Counseling Statement or Letter of Reprimand on a trooper (#381), written in the portal through the wiki
 * procedure (#565). Portal-owned (AD-22): written only by the audited `app/Commands/Counseling` Commands through
 * `CounselingRecordRepository`. Nothing is mass-assignable: every column is set by name in that repository.
 *
 * `issuer_unit_id` is the unit through whose command the issuer reached the trooper; the commanders of that unit (the
 * issuer's own chain) and Admins may work on the draft. `form_data` holds the fields of the regiment's form
 * (7CAV-R-042 for a DCS, 7CAV-R-043 for an LOR), the leader's and, once they respond, the trooper's. The form is filled
 * from them on request; nothing generated is kept. `document_*` names a **source document** on the private
 * `counseling` disk, kept only for a record filed with a PDF before #565 or brought in by the import ({@see source}).
 *
 * The lifecycle, stored as {@see CounselingStatus} and worked out on read from the timestamps after that ({@see state()}):
 *
 *  - **Draft**, then optionally **Invited** (the meeting PM went out; {@see MEETING_HOURS} hours to meet).
 *  - **Shared** at `shared_at`: Section 1 is snapshotted (`snapshot`) and the leader's fields lock. The trooper responds
 *    once (`responded_at`); without a response by {@see respondBy()} the record shows **No response** and stands.
 *  - An LOR's trooper may ask to appeal within {@see APPEAL_CHOICE_HOURS} hours (`appealed_at`). The commander of
 *    `appeal_unit_id` decides within {@see APPEAL_DECISION_HOURS} hours; past that the appeal lapses and the letter
 *    stands as written.
 *  - A record **freezes** {@see LOCK_HOURS} hours after it was shared, or, for an LOR, when its appeal is decided, but
 *    never while that appeal is pending ({@see isFrozen()}). After that only the Regimental Executive Officer can
 *    overturn an LOR (`overturned_*`), which keeps it on file and stops it counting.
 *
 * A DCS is **active** for {@see DCS_ACTIVE_DAYS} days after it was shared, **inactive** (hidden from the trooper and
 * their section leaders, still counting) up to {@see DCS_EXPIRY_DAYS} days, then **expired** (Admins only, no longer
 * counting, kept forever) ({@see dcsPhase()}). Worked out on every read, so it needs no job. An LOR never expires.
 */
class CounselingRecord extends Model
{
    /** @use HasFactory<CounselingRecordFactory> */
    use HasFactory;

    /** A DCS shared this many days ago is still active: the trooper sees it. */
    public const DCS_ACTIVE_DAYS = 30;

    /** A DCS shared this many days ago still counts; one day later it has expired. */
    public const DCS_EXPIRY_DAYS = 90;

    /** How long after it is shared a DCS or LOR may still be withdrawn: then it freezes. */
    public const LOCK_HOURS = 72;

    /** How long after the meeting invite the trooper and the leader have to meet. */
    public const MEETING_HOURS = 72;

    /** How long after an LOR is shared its trooper has to say whether they appeal. */
    public const APPEAL_CHOICE_HOURS = 24;

    /** How long after the trooper asks the appeal authority has to decide. */
    public const APPEAL_DECISION_HOURS = 72;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => CounselingType::class,
            'kind' => CounselingKind::class,
            'status' => CounselingStatus::class,
            'source' => CounselingSource::class,
            'version' => 'integer',
            'needs_review' => 'boolean',
            'counseled_on' => 'immutable_date',
            'document_size' => 'integer',
            'form_data' => 'array',
            'meeting' => 'array',
            'invited_at' => 'immutable_datetime',
            'meeting_confirmed' => 'boolean',
            'snapshot' => 'array',
            'shared_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'overturned_at' => 'immutable_datetime',
            'appealed_at' => 'immutable_datetime',
            'appeal_decided_at' => 'immutable_datetime',
            'appeal_outcome' => AppealOutcome::class,
            'appeal_authority' => 'array',
            'appeal_original' => 'array',
        ];
    }

    /**
     * The name the document goes by on download: `DCS 2026-10-20 Brandt.L.pdf`. Made from the record, never from an
     * uploaded file's name, and kept to characters every filesystem and Content-Disposition accepts.
     */
    public function fileName(Trooper $trooper): string
    {
        $who = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $trooper->username);
        $date = $this->counseled_on?->toDateString() ?? 'draft';

        return trim("{$this->type->short()} {$date} ".($who !== '' ? $who : "trooper-{$trooper->id}")).'.pdf';
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    public function isShared(): bool
    {
        return $this->status === CounselingStatus::Shared && $this->shared_at !== null;
    }

    /** Whether a source document (an upload from before #565, or an import) is kept for this record. */
    public function hasSourceDocument(): bool
    {
        return $this->document_path !== null;
    }

    /** When the meeting window of the invite closes, or null without an invite. */
    public function meetingClosesAt(): ?CarbonImmutable
    {
        return $this->invited_at?->addHours(self::MEETING_HOURS);
    }

    /**
     * When the record freezes if nothing else happens: {@see LOCK_HOURS} hours after it was shared, never from the date
     * it carries. Null for a record not shared.
     */
    public function freezesAt(): ?CarbonImmutable
    {
        return $this->isShared() ? $this->shared_at->addHours(self::LOCK_HOURS) : null;
    }

    /**
     * Whether the record is frozen: an LOR whose appeal was decided is; one whose appeal is still pending is not; any
     * other shared record is from {@see freezesAt()} on. A draft never is.
     */
    public function isFrozen(?CarbonImmutable $now = null): bool
    {
        if (! $this->isShared()) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        if ($this->isAppealDecided()) {
            return true;
        }

        if ($this->isAppealPending($now)) {
            return false;
        }

        return $now->greaterThanOrEqualTo($this->freezesAt());
    }

    /**
     * Until when the trooper may respond: {@see APPEAL_CHOICE_HOURS} hours after an LOR was shared (its appeal choice,
     * as the wiki's After-Action PM says), or until a DCS freezes. Null for a record not shared.
     */
    public function respondBy(): ?CarbonImmutable
    {
        if (! $this->isShared()) {
            return null;
        }

        return $this->type === CounselingType::Lor
            ? $this->shared_at->addHours(self::APPEAL_CHOICE_HOURS)
            : $this->freezesAt();
    }

    public function isResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /** Shared, unanswered, and past {@see respondBy()}: the record stands as written. */
    public function isUnanswered(?CarbonImmutable $now = null): bool
    {
        return $this->isShared() && ! $this->isResponded()
            && ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->respondBy());
    }

    /** Whether the trooper asked to appeal (an LOR), or, on a record from before #565, filed a comment or dispute. */
    public function isAppealed(): bool
    {
        return $this->appealed_at !== null;
    }

    /** When the appeal authority has to have decided by, or null without an appeal. */
    public function appealDueAt(): ?CarbonImmutable
    {
        return $this->type === CounselingType::Lor ? $this->appealed_at?->addHours(self::APPEAL_DECISION_HOURS) : null;
    }

    public function isAppealDecided(): bool
    {
        return $this->appeal_decided_at !== null;
    }

    /** An LOR appeal asked for, not decided, and still within its {@see APPEAL_DECISION_HOURS} hours. */
    public function isAppealPending(?CarbonImmutable $now = null): bool
    {
        return $this->type === CounselingType::Lor
            && $this->isAppealed()
            && ! $this->isAppealDecided()
            && ($now ?? CarbonImmutable::now())->lessThan($this->appealDueAt());
    }

    /**
     * The step the record is at, for the page: `draft`, `invited`, `shared` (waiting for the trooper), `acknowledged`,
     * `no_response`, `appealed` (pending), `decided` or `overturned`. Whether it is frozen is {@see isFrozen()}.
     */
    public function state(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $this->isOverturned() => 'overturned',
            $this->status === CounselingStatus::Draft => 'draft',
            $this->status === CounselingStatus::Invited => 'invited',
            $this->isAppealDecided() => 'decided',
            $this->isAppealPending($now) => 'appealed',
            $this->isResponded() => 'acknowledged',
            $this->isUnanswered($now) => 'no_response',
            default => 'shared',
        };
    }

    /**
     * A DCS's window by the day it was shared (UTC): `active` up to {@see DCS_ACTIVE_DAYS} days after, `inactive` up to
     * {@see DCS_EXPIRY_DAYS}, `expired` from the day after. Null for an LOR or a record not shared.
     */
    public function dcsPhase(?CarbonImmutable $today = null): ?string
    {
        if ($this->type !== CounselingType::Dcs || ! $this->isShared()) {
            return null;
        }

        $days = (int) $this->shared_at->utc()->startOfDay()->diffInDays(($today ?? CarbonImmutable::now('UTC'))->startOfDay());

        return match (true) {
            $days <= self::DCS_ACTIVE_DAYS => 'active',
            $days <= self::DCS_EXPIRY_DAYS => 'inactive',
            default => 'expired',
        };
    }

    /** The day a shared DCS turns inactive (the trooper no longer sees it), or null. */
    public function inactiveOn(): ?CarbonImmutable
    {
        return $this->type === CounselingType::Dcs && $this->isShared()
            ? $this->shared_at->utc()->startOfDay()->addDays(self::DCS_ACTIVE_DAYS + 1)
            : null;
    }

    /** The day a shared DCS expires (the first day it no longer counts), or null for an LOR or a draft. */
    public function expiresOn(): ?CarbonImmutable
    {
        return $this->type === CounselingType::Dcs && $this->isShared()
            ? $this->shared_at->utc()->startOfDay()->addDays(self::DCS_EXPIRY_DAYS + 1)
            : null;
    }

    /** Whether this is a DCS shared more than {@see DCS_EXPIRY_DAYS} days before `$today` (UTC by default). */
    public function isExpired(?CarbonImmutable $today = null): bool
    {
        return $this->dcsPhase($today) === 'expired';
    }

    public function isOverturned(): bool
    {
        return $this->overturned_at !== null;
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** The Admin who started this while acting as `issued_by` (#379), or null. */
    public function issuedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_admin');
    }

    /** The unit whose billet `issued_by`, an Admin, held when starting it (#379), or null. */
    public function issuedAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'issued_as_unit');
    }

    /** The trooper who responded, or null. */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** The trooper who appealed, or null. */
    public function appealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appealed_by');
    }

    /** The unit whose commander decides an appeal of this LOR, or null. */
    public function appealUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'appeal_unit_id');
    }

    /** The appeal authority who decided, or null. */
    public function appealDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_decided_by');
    }

    /** The one who decided acting as `appeal_decided_by` (#379), or null. */
    public function appealDecidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_decided_by_admin');
    }

    /** The Regimental Executive Officer who overturned this LOR, or null. */
    public function overturnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overturned_by');
    }

    /** The one who overturned it acting as `overturned_by` (#379, #432), or null. */
    public function overturnedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overturned_by_admin');
    }

    /** The unit whose billet `overturned_by` held while acting as one, or null. */
    public function overturnedAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'overturned_as_unit');
    }
}
