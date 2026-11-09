<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Models\User;
use App\Repositories\NotificationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header's bell: the signed-in user's own notifications, read when it opens, and marking them read. Every read and
 * mark goes through {@see NotificationRepository} with the signed-in user's id, so an id from anyone else's
 * notifications is a 404, the same as one that does not exist. Only to a user signed in as themselves: during a
 * view-as preview or while an Admin acts as someone the list is empty and a mark is refused, so nobody reads or
 * clears another person's bell. Marking read is not audited: it changes nothing but the user's own bell.
 */
class NotificationController extends Controller
{
    /** How many the bell lists. */
    public const LIMIT = 20;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        return response()->json([
            'notifications' => $user === null ? [] : $this->notifications->recentFor($user->id, self::LIMIT),
            'unread' => $user === null ? 0 : $this->notifications->unreadCountFor($user->id),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $this->owner($request) ?? abort(403, 'Notifications are marked read only by their owner, signed in as themselves.');

        if (! $this->notifications->markRead($user->id, $notification)) {
            abort(404);
        }

        return response()->json(['unread' => $this->notifications->unreadCountFor($user->id)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $this->owner($request) ?? abort(403, 'Notifications are marked read only by their owner, signed in as themselves.');

        $this->notifications->markAllRead($user->id);

        return response()->json(['unread' => 0]);
    }

    /** The signed-in user, or null during a view-as preview or while an Admin acts as someone. */
    private function owner(Request $request): ?User
    {
        $user = $request->user();

        if ($user === null || $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) !== null || $this->scope->activeViewAs($user) !== null) {
            return null;
        }

        return $user;
    }
}
