<?php

namespace App\Repositories\BookmarkRepository;

use App\Models\Bookmark;
use App\Repositories\BookmarkRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class Eloquent implements BookmarkRepository
{
    public function forUser(int $userId): Collection
    {
        return $this->of($userId)->orderBy('position')->orderBy('id')->get();
    }

    public function countFor(int $userId): int
    {
        return $this->of($userId)->count();
    }

    public function add(int $userId, string $route, string $params, string $label, int $limit): ?Bookmark
    {
        try {
            return DB::transaction(function () use ($userId, $route, $params, $label, $limit) {
                // Locks the user's rows, so two adds at once cannot both take the last slot.
                $rows = $this->of($userId)->lockForUpdate()->get(['id', 'route', 'params', 'position']);

                if ($rows->count() >= $limit || $rows->contains(fn (Bookmark $row) => $row->route === $route && $row->params === $params)) {
                    return null;
                }

                return Bookmark::query()->create([
                    'user_id' => $userId,
                    'route' => $route,
                    'params' => $params,
                    'label' => $label,
                    'position' => ((int) $rows->max('position')) + 1,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function remove(int $userId, int $id): bool
    {
        return $this->of($userId)->whereKey($id)->delete() > 0;
    }

    public function removeMany(int $userId, array $ids): int
    {
        return $ids === [] ? 0 : $this->of($userId)->whereKey($ids)->delete();
    }

    public function move(int $userId, int $id, bool $up): bool
    {
        return DB::transaction(function () use ($userId, $id, $up) {
            $rows = $this->of($userId)->lockForUpdate()->orderBy('position')->orderBy('id')->get()->values();
            $index = $rows->search(fn (Bookmark $row) => $row->id === $id);

            if ($index === false) {
                return false;
            }

            $other = $rows->get($up ? $index - 1 : $index + 1);
            if ($other === null) {
                return true;
            }

            // Renumbered from the list's order, so equal or missing positions never stop a swap.
            $order = $rows->pluck('id')->all();
            [$order[$index], $order[$up ? $index - 1 : $index + 1]] = [$order[$up ? $index - 1 : $index + 1], $order[$index]];
            foreach ($order as $position => $rowId) {
                $this->of($userId)->whereKey($rowId)->update(['position' => $position + 1]);
            }

            return true;
        });
    }

    /** @return Builder<Bookmark> */
    private function of(int $userId): Builder
    {
        return Bookmark::query()->where('user_id', $userId);
    }
}
