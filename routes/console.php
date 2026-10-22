<?php

use App\Commands\Demo\GenerateDemoHistory;
use App\Commands\Demo\GenerateDemoHistoryHandler;
use App\Commands\Demo\PurgeDemoHistory;
use App\Commands\Demo\PurgeDemoHistoryHandler;
use App\Commands\Users\SetAdminFlag;
use App\Jobs\SyncLoasJob;
use App\Jobs\SyncRosterJob;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Roster sync (Story 2.4, AD-23). Cadence is Deferred to an S6 conversation — read from
// config so changing it needs no code change. `SyncRosterJob` defaults `triggeredBy` to
// `system`; the manual-trigger controller dispatches the same job with a user id instead.
Schedule::job(new SyncRosterJob)->cron(config('sync.roster_cron'));

// Leave sync (Story 4.2). Same shape, offset from the roster run. It only runs once the forum API key and the
// leave sections are configured, so an install without them does not record a failed run every hour.
Schedule::job(new SyncLoasJob)->cron(config('sync.loa_cron'))->when(fn () => SyncLoasJob::isConfigured());

// A daily full run: the hourly ones only read threads newer than the last good run, so a post edited after that
// (an LOA extended, say) is caught here. It skips leave that ended more than two weeks ago.
Schedule::job(new SyncLoasJob('system', true))->cron(config('sync.loa_full_cron'))->when(fn () => SyncLoasJob::isConfigured());

// Bootstrap or remove an Admin without SQL (story 2.9). The user must already exist — i.e. have
// signed in once via 7Cav SSO. Audited through `SetAdminFlag` (AD-16); see its docblock for why
// the target is recorded as the actor. Every account that finishes the forum sign-in has a row, refused
// non-members included, so the command shows which account it matched (forum id, Cav profile, roster
// status) before changing anything. A username must match exactly (the column's collation ignores case
// and accents) and the change must be confirmed; `--forum-id` names the account outright and needs no
// prompt, for scripts. Granting Admin to an account with no Cav profile linked needs `--force`.
Artisan::command('portal:admin {username? : The exact forum username; the match is shown and must be confirmed}
    {--forum-id= : The forum user id instead of a username; no confirmation prompt}
    {--revoke : Clear the Admin flag instead of setting it}
    {--force : Grant even though the account has no Cav profile linked}', function (UserRepository $users, TrooperRepository $troopers, SetAdminFlag $setAdminFlag) {
    $username = $this->argument('username');
    $forumId = $this->option('forum-id');

    if (($username === null) === ($forumId === null)) {
        $this->error('Name the user by forum username or by --forum-id (one of the two).');

        return 1;
    }

    if ($forumId !== null) {
        $user = ctype_digit((string) $forumId) ? $users->withForumUserId((int) $forumId) : null;

        if ($user === null) {
            $this->error("No portal user has forum id '{$forumId}'. They must sign in once before they can be made Admin.");

            return 1;
        }
    } else {
        $username = (string) $username;
        $matches = $users->withUsername($username);
        $exact = $matches->filter(fn (User $match) => $match->username === $username)->values();

        if ($exact->isEmpty()) {
            $near = $matches->map(fn (User $match) => "{$match->username} (forum id {$match->forum_user_id})")->implode(', ');
            $this->error("No portal user is named '{$username}'. They must sign in once before they can be made Admin."
                .($near === '' ? '' : " Not an exact match: {$near}."));

            return 1;
        }

        if ($exact->count() > 1) {
            $this->error("{$exact->count()} portal users are named '{$username}'; refusing to guess which one. Use --forum-id.");

            return 1;
        }

        $user = $exact->first();
    }

    $status = $user->cav_id === null ? null : $troopers->statusOf((int) $user->cav_id);
    $this->line("Matched {$user->username}, forum id {$user->forum_user_id}: ".match (true) {
        $user->cav_id === null => 'no Cav profile linked.',
        $status === null => 'Cav profile '.Trooper::MILPAC_PROFILE_URL."{$user->cav_id}, not on the synced roster.",
        default => 'Cav profile '.Trooper::MILPAC_PROFILE_URL."{$user->cav_id}, roster status {$status->value} (".($status->isServing() ? 'serving' : 'not serving').').',
    });

    $grant = ! $this->option('revoke');

    if ($grant === (bool) $user->is_admin) {
        $this->info("{$user->username} is already ".($grant ? 'an Admin' : 'not an Admin').'; nothing changed.');

        return 0;
    }

    if ($grant && $user->cav_id === null && ! $this->option('force')) {
        $this->error("{$user->username} has no Cav profile linked, so the portal cannot tell they are a 7Cav member. Nothing changed. Re-run with --force if this is the right account.");

        return 1;
    }

    if ($forumId === null && ! $this->confirm($grant ? "Make {$user->username} an Admin?" : "Remove Admin from {$user->username}?")) {
        $this->warn('Nothing changed. Without a terminal, name the user with --forum-id instead.');

        return 1;
    }

    $setAdminFlag->handle($user, $user, $grant, 'Set from the console: php artisan portal:admin'.($grant ? '' : ' --revoke'));

    $this->info($grant ? "{$user->username} is now an Admin." : "{$user->username} is no longer an Admin.");

    return 0;
})->purpose('Grant (or with --revoke, remove) the portal Admin flag for an existing user');

// Demo attendance history for the real roster (#115). Off unless DEMO_TOOLS=true. The console has
// no portal identity, so the audited actor (and the reports' sender) is the `--as` user or, by
// default, the earliest Admin — bootstrap one with `portal:admin` first. `{unit}` is a 7Cav
// designation (`A/1-7`) or a unit id; the whole subtree is generated or purged.
Artisan::command('portal:demo-history {unit : A unit designation (A/1-7) or id}
    {--weeks='.GenerateDemoHistory::DEFAULT_WEEKS.' : Completed Cav weeks to generate (1-'.GenerateDemoHistory::MAX_WEEKS.')}
    {--purge : Delete the subtree\'s demo history instead of generating it}
    {--as= : Username of the Admin to record as the actor (default: the earliest Admin)}', function (
    UnitRepository $units,
    UserRepository $users,
    GenerateDemoHistoryHandler $generator,
    PurgeDemoHistoryHandler $purger,
) {
    if (! config('portal.demo_tools')) {
        $this->error('Demo tools are disabled. Set DEMO_TOOLS=true in .env to use them.');

        return 1;
    }

    $designation = trim((string) $this->argument('unit'));
    $matches = ctype_digit($designation)
        ? collect([$units->find((int) $designation)])->filter()
        : $units->withCallsign($designation);

    if ($matches->count() !== 1) {
        $this->error($matches->isEmpty()
            ? "No unit is designated '{$designation}'. Import the org structure first (Settings → Org structure)."
            : "{$matches->count()} units are designated '{$designation}'; pass the unit id instead.");

        return 1;
    }

    $as = $this->option('as');
    $actors = $as === null ? collect([$users->firstAdmin()])->filter() : $users->withUsername((string) $as);
    $actor = $actors->count() === 1 ? $actors->first() : null;

    if ($actor === null || ! $actor->is_admin) {
        $this->error($as === null
            ? 'There is no Admin to record as the actor. Run php artisan portal:admin <username> first.'
            : "'{$as}' is not exactly one Admin user.");

        return 1;
    }

    $this->line("Recording as {$actor->username}".($as === null ? ' (the earliest Admin; pass --as to choose).' : '.'));

    if ($this->option('purge')) {
        $this->info($purger->handle($actor, new PurgeDemoHistory($matches->first()->id))->summary());

        return 0;
    }

    $weeks = filter_var($this->option('weeks'), FILTER_VALIDATE_INT);

    if ($weeks === false || $weeks < 1 || $weeks > GenerateDemoHistory::MAX_WEEKS) {
        $this->error('--weeks must be a whole number from 1 to '.GenerateDemoHistory::MAX_WEEKS.'.');

        return 1;
    }

    $result = $generator->handle($actor, new GenerateDemoHistory($matches->first()->id, $weeks));
    $result->hasNoTroopers() || $result->generatedWeeks === [] ? $this->warn($result->summary()) : $this->info($result->summary());

    return 0;
})->purpose('Generate (or with --purge, remove) demo attendance history for a unit\'s real troopers');
