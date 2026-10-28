<?php

namespace App\Models;

use Database\Factories\WeeklyReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit's weekly report for one Cav week (AD-12) — UNIQUE on `(unit_id, week_start)`, `status`
 * draft|sent. Once sent, `kpis` and `notes` are the frozen snapshot: the canonical in-portal record
 * of what was reported, never recomputed. Portal-owned (AD-22): the Repository layer is its only
 * writer, and its send path is driven by 5.6's audited send Command.
 *
 * `week_start` is always a Saturday 00:00 UTC (AD-11); the Repository layer refuses anything else.
 * `kpis` is storage-shaped — a plain array of the six KPIs with numerators/denominators and deltas,
 * mapped to and from 5.1's typed result by the caller, not here. Keys and values round-trip; object
 * key *order* does not on MySQL, whose JSON type normalizes it, so readers look keys up by name.
 *
 * `training_subject` and `unaccounted` (`[{trooper_id, reason}]`, an `UnaccountedReason` value
 * each) are the structured half of the leader's notes (5.5): saved with a draft, frozen with
 * `notes` on send.
 *
 * `status` and the send metadata (`sent_at`, `sent_by`, `content_digest`, `template_version_id`)
 * are deliberately not mass-assignable: only the Repository layer's send path sets them, so a
 * stray `update()` can neither reopen a sent report nor mark one sent without its metadata.
 */
#[Fillable(['unit_id', 'week_start', 'kpis', 'notes'])]
class WeeklyReport extends Model
{
    /** @use HasFactory<WeeklyReportFactory> */
    use HasFactory;

    /** Mirrors the column's DB default, so a freshly created model reads it without a refresh. */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'status' => WeeklyReportStatus::class,
            'kpis' => 'array',
            'unaccounted' => 'array',
            'sent_at' => 'immutable_datetime',
            // Demo history (#115): set only by the demo writer, never mass-assigned.
            'is_demo' => 'boolean',
        ];
    }

    /**
     * Keeps a whole-number float (a `0.0` rate or delta) a float through the JSON column, so the
     * `kpis` array reads back exactly as it was written rather than with `0.0` turned into `0`.
     */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** The unit whose billet the sender, an Admin, held when sending it (#379), or null. */
    public function sentAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sent_as_unit');
    }
}
