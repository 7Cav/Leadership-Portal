<?php

namespace App\Repositories\AdminBilletRepository;

use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Repositories\AdminBilletRepository;
use Illuminate\Support\Collection;

class Eloquent implements AdminBilletRepository
{
    private const ORDER = [
        AdminBilletStatus::Pending->value => 0,
        AdminBilletStatus::Rejected->value => 1,
        AdminBilletStatus::Approved->value => 2,
    ];

    public function forPositions(array $positionIds): Collection
    {
        if ($positionIds === []) {
            return collect();
        }

        return AdminBillet::query()->whereIn('cav_position_id', $positionIds)->get()->keyBy('cav_position_id')->toBase();
    }

    public function all(): Collection
    {
        return AdminBillet::query()
            ->orderBy('id')
            ->get()
            ->sortBy(fn (AdminBillet $billet) => [self::ORDER[$billet->status->value], mb_strtolower((string) $billet->proposed_title), $billet->id])
            ->values()
            ->toBase();
    }

    public function find(int $id): ?AdminBillet
    {
        return AdminBillet::query()->find($id);
    }

    public function pendingCount(): int
    {
        return AdminBillet::query()->where('status', AdminBilletStatus::Pending->value)->count();
    }

    public function observe(array $seen, bool $createMissing): int
    {
        $rows = $this->forPositions(array_keys($seen));
        $written = 0;

        foreach ($seen as $positionId => [$title, $group]) {
            $row = $rows->get($positionId);

            if ($row === null) {
                if ($createMissing) {
                    AdminBillet::query()->create([
                        'cav_position_id' => $positionId,
                        'proposed_title' => $title,
                        'proposed_group_title' => $group,
                        'status' => AdminBilletStatus::Pending,
                    ]);
                    $written++;
                }

                continue;
            }

            $group ??= $row->proposed_group_title;
            $changed = $title !== $row->proposed_title || $group !== $row->proposed_group_title;
            $row->proposed_title = $title;
            $row->proposed_group_title = $group;

            if (($row->status === AdminBilletStatus::Approved && $row->renamed())
                || ($row->status === AdminBilletStatus::Rejected && $changed)) {
                $row->status = AdminBilletStatus::Pending;
            }

            if ($row->isDirty()) {
                $row->save();
                $written++;
            }
        }

        return $written;
    }

    public function lockForDecision(int $id): ?AdminBillet
    {
        return AdminBillet::query()->lockForUpdate()->find($id);
    }

    public function decide(AdminBillet $billet, AdminBilletStatus $status, int $decidedBy, ?int $decidedByAdmin, ?int $decidedAsUnit): AdminBillet
    {
        if ($status === AdminBilletStatus::Approved) {
            $billet->title = $billet->proposed_title;
            $billet->group_title = $billet->proposed_group_title;
        }

        $billet->forceFill([
            'status' => $status,
            'decided_by' => $decidedBy,
            'decided_by_admin' => $decidedByAdmin,
            'decided_as_unit' => $decidedAsUnit,
            'decided_at' => now(),
        ])->save();

        return $billet;
    }
}
