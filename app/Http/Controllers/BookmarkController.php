<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Bookmarks\BookmarkablePages;
use App\Models\Bookmark;
use App\Models\User;
use App\Repositories\BookmarkRepository;
use App\Tutorial\TutorialSandbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The sidebar's bookmarks: add the page on screen, remove one, move one up or down. Only for the person signed in as
 * themselves: during a view-as preview, while an Admin acts as someone and in the tutorial every write is refused, as
 * the shell hides the bookmarks then. A page is added only from the allowlist and only while the person may open it
 * ({@see BookmarkablePages}). Every write goes through {@see BookmarkRepository} with the signed-in user's id, so an id
 * from anyone else's list is a 404. Not audited: bookmarks are a personal preference and change nothing anyone else
 * sees, like marking a notification read.
 */
class BookmarkController extends Controller
{
    public function __construct(
        private readonly BookmarkRepository $bookmarks,
        private readonly BookmarkablePages $pages,
        private readonly ScopeResolver $scope,
        private readonly TutorialSandbox $sandbox,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->owner($request);
        $input = $request->validate([
            'route' => ['required', 'string', 'max:64'],
            'params' => ['present', 'array'],
        ]);

        $params = $this->pages->normalize($input['route'], $input['params']);
        if ($params === null) {
            throw ValidationException::withMessages(['bookmark' => 'This page cannot be bookmarked.']);
        }

        $page = $this->pages->openable($user, [['route' => $input['route'], 'params' => $params]])[0];
        abort_if($page === null, 403, 'You cannot open that page, so it cannot be bookmarked.');

        $encoded = Bookmark::encodeParams($params);
        $existing = $this->bookmarks->forUser($user->id);

        // Already bookmarked: nothing to add, and no second row.
        if ($existing->contains(fn (Bookmark $row) => $row->route === $input['route'] && $row->params === $encoded)) {
            return back();
        }

        if ($existing->count() >= Bookmark::LIMIT || $this->bookmarks->add($user->id, $input['route'], $encoded, mb_substr($page['label'], 0, 120), Bookmark::LIMIT) === null) {
            throw ValidationException::withMessages(['bookmark' => 'All '.Bookmark::LIMIT.' bookmark slots are in use. Remove one to add this page.']);
        }

        return back();
    }

    public function destroy(Request $request, int $bookmark): RedirectResponse
    {
        abort_unless($this->bookmarks->remove($this->owner($request)->id, $bookmark), 404);

        return back();
    }

    public function move(Request $request, int $bookmark): RedirectResponse
    {
        $user = $this->owner($request);
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        abort_unless($this->bookmarks->move($user->id, $bookmark, $direction === 'up'), 404);

        return back();
    }

    /** The signed-in user, refused during a view-as preview, while an Admin acts as someone and in the tutorial. */
    private function owner(Request $request): User
    {
        $user = $request->user();

        abort_if(
            $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) !== null || $this->sandbox->entered() || $this->scope->activeViewAs($user) !== null,
            403,
            'Bookmarks are kept only by the person signed in as themselves.',
        );

        return $user;
    }
}
