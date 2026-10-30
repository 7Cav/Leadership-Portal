<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CounselingRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Developmental Counseling Statement or Letter of Reprimand filed on a trooper (#381), with the signed PDF the
 * leader attached. Portal-owned (AD-22): written only by the audited `app/Commands/Counseling` Commands through
 * `CounselingRecordRepository`. Nothing is mass-assignable: every column is set by name in that repository, so a
 * request field can never reach `document_path`, the issuer or the Drive status.
 *
 * `issuer_unit_id` is the unit through whose command the issuer reached the trooper when filing; the commanders of that
 * unit (the issuer's own chain) and Admins may correct or delete the record. `document_path` names a file on the private
 * `counseling` disk that the portal named itself; `document_sha256` is what the audit digest carries of the file.
 * `kind` says which ladder of the regulations the record belongs to ({@see CounselingKind}); null on a record filed
 * before the portal asked, ruled as other conduct.
 *
 * `form_data` holds what the leader filled in when the portal generated the document from its own form (the
 * regiment's 7CAV-R-042 or 7CAV-R-043 layout), so the PDF can be made again; null for an uploaded document.
 *
 * A DCS or LOR is frozen 72 hours after it is filed (`CounselingPermissions::frozen()`, {@see freezesAt()}). Within
 * those 72 hours the trooper may file one appeal (on an LOR) or one comment or dispute (on a DCS): `appealed_at`,
 * `appealed_by` and `appeal_statement`. After that only the Regimental Executive Officer can overturn an LOR, which sets `overturned_at`, who did it (`overturned_by`, with
 * `overturned_by_admin` and `overturned_as_unit` as for the issuer) and `overturn_reason`. An overturned LOR stays on
 * file and no longer counts toward the counseling rules.
 *
 * A DCS **expires** {@see DCS_EXPIRY_DAYS} days after the date it was counseled ({@see isExpired()}): worked out from
 * `counseled_on` on every read, so it needs no job. It is never deleted: the row, its PDF and its Drive copy stay, seen
 * by Admins only. An LOR never expires.
 */
class CounselingRecord extends Model
{
    /** @use HasFactory<CounselingRecordFactory> */
    use HasFactory;

    /** A DCS counseled this many days ago is still active; one day later it has expired. */
    public const DCS_EXPIRY_DAYS = 90;

    /** How long after it is filed a DCS or LOR may still be corrected, deleted or appealed: its appeal window. */
    public const APPEAL_HOURS = 72;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => CounselingType::class,
            'kind' => CounselingKind::class,
            'counseled_on' => 'immutable_date',
            'document_size' => 'integer',
            'drive_status' => DriveUploadStatus::class,
            'drive_attempted_at' => 'immutable_datetime',
            'overturned_at' => 'immutable_datetime',
            'appealed_at' => 'immutable_datetime',
            'form_data' => 'array',
        ];
    }

    /**
     * The name the document goes by on download and on the Drive: `DCS 2026-10-20 Brandt.L.pdf`. Made from the record,
     * never from the uploaded file's name, and kept to characters every filesystem and Content-Disposition accepts.
     */
    public function fileName(Trooper $trooper): string
    {
        $who = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $trooper->username);

        return trim("{$this->type->short()} {$this->counseled_on->toDateString()} ".($who !== '' ? $who : "trooper-{$trooper->id}")).'.pdf';
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** The Admin who filed this while acting as `issued_by` (#379), or null. */
    public function issuedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_admin');
    }

    /** The unit whose billet `issued_by`, an Admin, held when filing (#379), or null. */
    public function issuedAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'issued_as_unit');
    }

    /** The day a DCS expires (the first day it is no longer active), or null for an LOR, which never expires. */
    public function expiresOn(): ?CarbonImmutable
    {
        return $this->type === CounselingType::Dcs ? $this->counseled_on->addDays(self::DCS_EXPIRY_DAYS + 1) : null;
    }

    /**
     * Whether this is a DCS counseled more than {@see DCS_EXPIRY_DAYS} days before `$today` (UTC by default): on day 90
     * it is still active, from day 91 it has expired.
     */
    public function isExpired(?CarbonImmutable $today = null): bool
    {
        $expires = $this->expiresOn();
        $today ??= CarbonImmutable::now('UTC');

        return $expires !== null && $today->startOfDay()->greaterThanOrEqualTo($expires->startOfDay());
    }

    /**
     * When the record freezes: {@see APPEAL_HOURS} hours after it was filed
     * (`created_at`), never from the date it carries. Null for a record not yet saved.
     */
    public function freezesAt(): ?CarbonImmutable
    {
        return $this->created_at === null
            ? null
            : CarbonImmutable::instance($this->created_at)->addHours(self::APPEAL_HOURS);
    }

    /** Whether the trooper filed their appeal (LOR) or comment or dispute (DCS) on this record. */
    public function isAppealed(): bool
    {
        return $this->appealed_at !== null;
    }

    /** The trooper who appealed, or null. */
    public function appealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appealed_by');
    }

    public function isOverturned(): bool
    {
        return $this->overturned_at !== null;
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
