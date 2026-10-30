<?php

namespace App\Authorization;

use App\Models\User;
use App\Repositories\ChangelogRepository;
use Inertia\Inertia;

/**
 * Who may write the changelog, and whether the "Changelog outdated" bar shows.
 *
 * An editor is a user whose username is in `portal.changelog_editors`, compared without case. They may write only while
 * really signed in as themselves: never while someone acts as them ({@see RealActor}), and never during a view-as
 * preview (AD-9). Everyone else, an Admin included, gets a 403 on every changelog write.
 *
 * The deploy id is the asset build version Inertia uses (`HandleInertiaRequests::version()`, a hash of the Vite
 * manifest), so it changes with every redeploy that builds the front end. `portal.changelog_deploy_id` overrides it:
 * a string is used as the id, `false` means there is none. A null id never shows the bar.
 */
class ChangelogAccess
{
    public function __construct(
        private readonly RealActor $real,
        private readonly ScopeResolver $scope,
        private readonly ChangelogRepository $changelog,
    ) {}

    public function isEditor(User $user): bool
    {
        $editors = array_map(fn ($name) => mb_strtolower(trim((string) $name)), (array) config('portal.changelog_editors', []));

        return in_array(mb_strtolower($user->username), $editors, true);
    }

    /** Whether `$user` may write the changelog on this request: an editor, signed in as themselves, not previewing. */
    public function mayEdit(User $user): bool
    {
        return $this->isEditor($user)
            && $this->real->get() === null
            && $this->scope->activeViewAs($user) === null;
    }

    public function deployId(): ?string
    {
        $override = config('portal.changelog_deploy_id');
        if ($override === false) {
            return null;
        }
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $version = Inertia::getVersion();

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** Whether `$user` sees the "Changelog outdated" bar: an editor, after a deploy nobody has added an entry for or dismissed. */
    public function showsOutdated(User $user): bool
    {
        if (! $this->mayEdit($user)) {
            return false;
        }

        $deployId = $this->deployId();

        return $deployId !== null && $deployId !== $this->changelog->acknowledgedDeployId();
    }

    /** Whether the sidebar shows the "new" dot: an entry written after `$user` last opened the changelog. */
    public function hasUnseen(User $user): bool
    {
        $latest = $this->changelog->latestCreatedAt();
        if ($latest === null) {
            return false;
        }

        $seen = $this->changelog->seenAt($user->id);

        return $seen === null || $latest > $seen;
    }
}
