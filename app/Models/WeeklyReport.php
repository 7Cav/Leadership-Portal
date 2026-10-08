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
 * of what was reported, never recomputed. Portal-owned (AD-22), with one creating writer — the
 * Repository layer, driven by 5.6's audited send Command.
 *
 * `week_start` is always a Saturday 00:00 UTC (AD-11); the Repository layer refuses anything else.
 * `kpis` is storage-shaped — a plain array of the six KPIs with numerators/denominators and deltas,
 * mapped to and from 5.1's typed result by the caller, not here.
 */
#[Fillable([
    'unit_id', 'week_start', 'status', 'kpis', 'notes',
    'sent_at', 'sent_by', 'content_digest', 'template_version_id',
])]
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
            'sent_at' => 'immutable_datetime',
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
}
