<?php

use App\Commands\Demo\GenerateDemoHistory;
use App\Commands\Demo\GenerateDemoHistoryHandler;
use App\Commands\Demo\PurgeDemoHistory;
use App\Commands\Demo\PurgeDemoHistoryHandler;
use App\Commands\Users\SetAdminFlag;
use App\Jobs\SyncLoasJob;
use App\Jobs\SyncRosterJob;
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
