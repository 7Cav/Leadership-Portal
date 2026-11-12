<?php

namespace App\Http\Controllers;

use App\Authorization\ReportEditAuthority;
use App\Models\ReportEditRequest;
use App\Models\User;
use App\Repositories\ReportEditRequestRepository;
use App\Repositories\ReportEditWindow;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;

/**
 * The `editRequest` prop of a sent weekly or staff report: until when it can be edited, whether it is locked, the
 * newest request to edit it and what this viewer may do about it (ask, or approve and deny).
 */
final class ReportEditProps
{
    public function __construct(
        private readonly ReportEditRequestRepository $requests,
        private readonly ReportEditAuthority $authority,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * @param  bool  $mayAsk  whether the viewer could send this report now (and is not previewing)
     * @return array<string, mixed>
     */
    public function for(User $viewer, int $unitId, ?int $trooperId, CarbonImmutable $weekStart, ?CarbonImmutable $unlockedUntil, bool $mayAsk, bool $previewing): array
    {
        $locked = ReportEditWindow::isLocked($weekStart, $unlockedUntil);
        $latest = $this->requests->latestFor($unitId, $trooperId, $weekStart);
        $pending = $latest?->status === ReportEditRequest::PENDING;

        return [
            'locked' => $locked,
            'editableUntil' => ReportEditWindow::editableUntil($weekStart, $unlockedUntil)->toIso8601String(),
            'canRequest' => $locked && $mayAsk && ! $pending && ! $previewing,
            'canDecide' => $pending && ! $previewing && $this->authority->mayDecide($viewer, $latest),
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $latest->status,
                'reason' => $latest->reason,
                'requestedBy' => $this->name($latest->requester),
                'requestedAt' => $latest->created_at?->toIso8601String(),
                'decidedBy' => $this->name($latest->decider),
                'decidedAt' => $latest->decided_at?->toIso8601String(),
                'note' => $latest->decision_note,
                'unlockedUntil' => $latest->unlocked_until?->toIso8601String(),
            ],
        ];
    }

    private function name(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $own = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->first();

        return $own?->display_name ?? $user->username;
    }
}
