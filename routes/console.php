<?php

use App\Authorization\ScopeResolver;
use App\Commands\Counseling\ImportAbortedException;
use App\Commands\Counseling\ImportCounselingRecords;
use App\Commands\Counseling\ImportCounselingRecordsHandler;
use App\Commands\Counseling\ImportCsv;
use App\Commands\Counseling\ImportRefusedException;
use App\Commands\Counseling\ImportRowResult;
use App\Commands\Counseling\ImportStaging;
use App\Commands\Counseling\ImportStagingFailed;
use App\Commands\Demo\GenerateDemoHistory;
use App\Commands\Demo\GenerateDemoHistoryHandler;
use App\Commands\Demo\PurgeDemoHistory;
use App\Commands\Demo\PurgeDemoHistoryHandler;
use App\Commands\Users\SetAdminFlag;
use App\Jobs\SyncLoasJob;
use App\Jobs\SyncRosterJob;
use App\Models\PageVisit;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\PageVisitRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// The syncs (Stories 2.4, 4.2), as commands so the scheduler and an operator start them the same way. Each queues
// its job stamped as triggered by `system` (the manual-trigger controllers dispatch the same jobs with a user id);
// `--now` runs it in the foreground instead, for a first sync or a check without a queue worker.
Artisan::command('portal:sync-roster {--now : Run the sync in the foreground instead of queueing it}', function () {
    $this->option('now') ? SyncRosterJob::dispatchSync() : SyncRosterJob::dispatch();

    $this->info($this->option('now') ? 'Roster sync finished.' : 'Roster sync queued.');

    return 0;
})->purpose('Sync the roster from the Cav API (queued unless --now)');

// Refuses on an install without the forum API key and leave sections, instead of recording a failed run.
Artisan::command('portal:sync-loas {--full : Re-read recent and open leave, not just threads newer than the last good run}
    {--now : Run the sync in the foreground instead of queueing it}', function () {
    if (! SyncLoasJob::isConfigured()) {
        $this->error('The LOA sync is not configured. Set SEVENCAV_FORUM_API_KEY and SEVENCAV_FORUM_LOA_NODES in .env.');

        return 1;
    }

    $full = (bool) $this->option('full');
    $this->option('now') ? SyncLoasJob::dispatchSync('system', $full) : SyncLoasJob::dispatch('system', $full);

    $this->info(($full ? 'Full LOA sync' : 'LOA sync').($this->option('now') ? ' finished.' : ' queued.'));

    return 0;
})->purpose('Sync leave (LOA/ELOA) threads from the forum (queued unless --now)');

// Hourly roster sync, and the leave sync offset to a quarter past so the two do not start together. The leave
// sync only runs once the forum key and the leave sections are configured, so an install without them does not
// log a refused run every hour.
Schedule::command('portal:sync-roster')->hourly();
Schedule::command('portal:sync-loas')->hourlyAt(15)->when(fn () => SyncLoasJob::isConfigured());

// A daily full run: once past its first read of the year, an hourly run reads only threads posted since the last, so a post edited after that
// (an LOA extended, say) is caught here. It skips leave that ended more than two weeks ago.
Schedule::command('portal:sync-loas --full')->dailyAt('03:30')->when(fn () => SyncLoasJob::isConfigured());

// The activity log keeps 30 days (#382). Deleted in chunks so the prune never holds a long lock on a table every page
// view writes to. Daily, at a quiet hour away from the 03:30 leave sync.
Artisan::command('portal:prune-page-visits', function (PageVisitRepository $visits) {
    $deleted = $visits->pruneBefore(now()->subDays(PageVisit::RETENTION_DAYS));

    $this->info("Deleted {$deleted} page ".($deleted === 1 ? 'visit' : 'visits').' older than '.PageVisit::RETENTION_DAYS.' days.');

    return 0;
})->purpose('Delete activity-log page visits older than '.PageVisit::RETENTION_DAYS.' days');

Schedule::command('portal:prune-page-visits')->dailyAt('04:15');

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
    ScopeResolver $scope,
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

    if ($actor === null || ! $scope->isAdmin($actor)) {
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

// The counseling import (#565) from the server, for an Admin with shell access: the same handler as Settings →
// Counseling import, so a row is judged alike. `{csv}` is the template filled in; `--files` the directory its `file`
// column names PDFs in. The console reads no form fields, so every row it stores is flagged for review, and a Last.F
// several troopers share is skipped (there is no picker). The console has no portal identity, so the audited actor is
// the `--as` Admin or, by default, the earliest Admin, as for the demo history; each record's audit entry says it ran
// from the console. Drive downloads go to a scratch batch that is deleted when the command ends.
Artisan::command('counseling:import {csv : The filled-in import template}
    {--files= : The directory holding the PDFs the file column names}
    {--dry-run : Say what each row would become; store nothing}
    {--as= : Username of the Admin to record as the actor (default: the earliest Admin)}', function (
    UserRepository $users,
    ScopeResolver $scope,
    ImportStaging $staging,
    ImportCounselingRecordsHandler $handler,
) {
    $csvPath = (string) $this->argument('csv');

    if (! is_file($csvPath) || ! is_readable($csvPath)) {
        $this->error("Cannot read {$csvPath}.");

        return 1;
    }

    $dir = $this->option('files');
    if ($dir !== null && ! is_dir((string) $dir)) {
        $this->error("--files: {$dir} is not a directory.");

        return 1;
    }

    $as = $this->option('as');
    $actors = $as === null ? collect([$users->firstAdmin()])->filter() : $users->withUsername((string) $as);
    $actor = $actors->count() === 1 ? $actors->first() : null;

    if ($actor === null || ! $scope->isAdmin($actor)) {
        $this->error($as === null
            ? 'There is no Admin to record as the actor. Run php artisan portal:admin <username> first.'
            : "'{$as}' is not exactly one Admin user.");

        return 1;
    }

    try {
        $rows = ImportCsv::parse((string) file_get_contents($csvPath));
    } catch (ImportRefusedException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    // The PDFs in --files, by lower-cased name, as the file column is matched. Only files directly in it.
    $files = [];
    foreach ($dir === null ? [] : (scandir((string) $dir) ?: []) as $name) {
        $path = rtrim((string) $dir, '/').'/'.$name;
        if (is_file($path) && str_ends_with(strtolower($name), '.pdf')) {
            $files[ImportCounselingRecordsHandler::fileKey($name)] = realpath($path);
        }
    }

    $dryRun = (bool) $this->option('dry-run');
    $this->line(($dryRun ? 'Dry run, recording nothing' : 'Importing').", as {$actor->username}".($as === null ? ' (the earliest Admin; pass --as to choose).' : '.'));

    $failure = null;

    try {
        $batch = $staging->scratch();
        $report = $handler->handle($actor, new ImportCounselingRecords(
            rows: $rows,
            files: $files,
            fields: [],
            downloadDir: $staging->downloadDir($batch),
            dryRun: $dryRun,
            viaConsole: true,
        ));
    } catch (ImportAbortedException $e) {
        // Stopped partway: show what was stored before it, then the reason.
        [$report, $failure] = [$e->report, $e->getMessage().' Cause: '.$e->getPrevious()?->getMessage()];
    } catch (ImportStagingFailed $e) {
        report($e);
        $this->error($e->getMessage()." ({$e->path})");

        return 1;
    } finally {
        if (isset($batch)) {
            $staging->delete($batch);
        }
    }

    $this->table(['Line', 'Source', 'Result', 'Type', 'Trooper', 'Issuer', 'Date', 'Why'], array_map(fn (ImportRowResult $row) => [
        $row->line,
        $row->label,
        match ($row->outcome) {
            ImportRowResult::IMPORT => $dryRun ? 'import' : 'imported',
            ImportRowResult::REVIEW => 'needs review',
            default => 'skipped',
        },
        $row->type?->short() ?? '',
        $row->trooperName ?? '',
        $row->issuerName ?? '',
        $row->counseledOn?->toDateString() ?? '',
        implode(' ', array_filter([$row->reason, ...$row->notes])),
    ], $report->rows));

    if ($failure !== null) {
        $this->error($failure);

        return 1;
    }

    $this->info($report->summary());

    return 0;
})->purpose('Import older DCSs and LORs from a CSV of Drive links and PDFs (Admins; --dry-run to check first)');
