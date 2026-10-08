<?php

use App\Commands\Users\SetAdminFlag;
use App\Jobs\SyncRosterJob;
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

// Bootstrap or remove an Admin without SQL (story 2.9). The user must already exist — i.e. have
// signed in once via 7Cav SSO. Audited through `SetAdminFlag` (AD-16); see its docblock for why
// the target is recorded as the actor.
Artisan::command('portal:admin {username} {--revoke : Clear the Admin flag instead of setting it}', function (UserRepository $users, SetAdminFlag $setAdminFlag) {
    $username = (string) $this->argument('username');
    $matches = $users->withUsername($username);

    if ($matches->isEmpty()) {
        $this->error("No portal user is named '{$username}'. They must sign in once before they can be made Admin.");

        return 1;
    }

    if ($matches->count() > 1) {
        $this->error("{$matches->count()} portal users are named '{$username}'; refusing to guess which one.");

        return 1;
    }

    $user = $matches->first();
    $grant = ! $this->option('revoke');

    $changed = $setAdminFlag->handle($user, $user, $grant, 'Set from the console: php artisan portal:admin'.($grant ? '' : ' --revoke'));

    $this->info(match (true) {
        ! $changed => "{$username} is already ".($grant ? 'an Admin' : 'not an Admin').'; nothing changed.',
        $grant => "{$username} is now an Admin.",
        default => "{$username} is no longer an Admin.",
    });

    return 0;
})->purpose('Grant (or with --revoke, remove) the portal Admin flag for an existing user');
