<?php

namespace App\Bookmarks;

use App\Models\Bookmark;
use App\Models\User;
use App\Repositories\BookmarkRepository;
use Illuminate\Http\Request;

/**
 * The sidebar's Bookmarks section and the top bar's bookmark button, shared with every page. Reading the list drops
 * every bookmark the person can no longer open (a unit gone from their scope or deleted, a trooper who left it, a
 * Settings page they lost): its row is deleted and it is not shown. Only ever for the person signed in as themselves;
 * the caller hides it during view as, act as and the tutorial.
 */
final class SidebarBookmarks
{
    public function __construct(
        private readonly BookmarkRepository $bookmarks,
        private readonly BookmarkablePages $pages,
    ) {}

    /**
     * `items` in the person's order (`id`, `label`, `href`), `limit`, and `current`: the page on screen when it can be
     * bookmarked (`route`, `params`, `label`, and `bookmarkId` when it already is), else null.
     *
     * @return array{limit: int, items: list<array{id: int, label: string, href: string}>, current: ?array{route: string, params: array<string, int|string>, label: string, bookmarkId: ?int}}
     */
    public function for(User $user, Request $request): array
    {
        $rows = $this->bookmarks->forUser($user->id)->values();
        $current = $this->pages->current($request);

        $pages = $rows->map(fn (Bookmark $row) => ['route' => $row->route, 'params' => $row->parameters()])->all();
        if ($current !== null) {
            $pages['current'] = $current;
        }
        $open = $this->pages->openable($user, $pages);

        $gone = $rows->filter(fn (Bookmark $row, int $index) => $open[$index] === null)->pluck('id')->all();
        $this->bookmarks->removeMany($user->id, $gone);

        $items = $rows
            ->filter(fn (Bookmark $row, int $index) => $open[$index] !== null)
            ->map(fn (Bookmark $row, int $index) => ['id' => $row->id, 'label' => $open[$index]['label'], 'href' => $open[$index]['href']])
            ->values()
            ->all();

        $currentRow = $current === null ? null : $rows->first(
            fn (Bookmark $row) => $row->route === $current['route'] && $row->params === Bookmark::encodeParams($current['params']),
        );

        return [
            'limit' => Bookmark::LIMIT,
            'items' => $items,
            'current' => $current === null || $open['current'] === null ? null : [
                ...$current,
                'label' => $open['current']['label'],
                'bookmarkId' => $currentRow?->id,
            ],
        ];
    }
}
