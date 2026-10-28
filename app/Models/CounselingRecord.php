<?php

namespace App\Models;

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
 */
class CounselingRecord extends Model
{
    /** @use HasFactory<CounselingRecordFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => CounselingType::class,
            'counseled_on' => 'immutable_date',
            'document_size' => 'integer',
            'drive_status' => DriveUploadStatus::class,
            'drive_attempted_at' => 'immutable_datetime',
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
}
