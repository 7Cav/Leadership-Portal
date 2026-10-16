# Leadership-Portal

The 7th Cavalry Regiment's leadership portal: rollcalls, Section Practice attendance and weekly
reports built on the live Cav roster. How to set up and contribute: see [CONTRIBUTING.md](CONTRIBUTING.md).
Running it on a server: see [Deploying](#deploying).

## Deploying

A production install is the Laravel app plus two long-running pieces: a queue worker and the
scheduler. All times below are UTC (`config/app.php` sets `timezone` to `UTC`).

### Environment

Start from `.env.example`; every key is documented there. For production:

- `APP_ENV=production` and `APP_DEBUG=false`. `APP_URL` is the public `https://` address; the
  7Cav SSO callback is built from it (`SEVENCAV_OAUTH_REDIRECT_URI`).
- `APP_KEY` is empty in `.env.example`. Set it once on a new install with
  `php artisan key:generate` and keep it: a new key signs everyone out.
- Serve over HTTPS. Leave `SESSION_SECURE_COOKIE` unset: it defaults to true when
  `APP_ENV=production` (`config/session.php`), so the session cookie is only sent over HTTPS.
  `Strict-Transport-Security` is sent only in production and only on a request the app sees as
  HTTPS (`SetSecurityHeaders`).
- Behind a load balancer or TLS-terminating proxy, set `TRUSTED_PROXIES` to the proxy's IPs or
  CIDRs (comma-separated, never `*`). Without it the app sees every request as coming from the
  proxy (one sign-in throttle bucket for everyone) and, when the proxy terminates TLS, as plain
  HTTP (no HSTS). It is read from config (`portal.trusted_proxies`) on each request, so setting it
  in `.env` works; run `config:cache` again after changing it.
- `DEMO_TOOLS=false` (the default). It switches on `portal:demo-history` and Settings → Demo
  data, which write demo rows into the real database.
- `QUEUE_CONNECTION=database` (the default) needs no extra service; see the queue worker below
  for `DB_QUEUE_RETRY_AFTER`.

### Install and update

```bash
composer install --no-dev --optimize-autoloader
npm ci                       # the build tools are dev dependencies, so keep NODE_ENV unset here
npm run build
php artisan migrate --force
php artisan config:cache     # re-run after every .env change
php artisan queue:restart    # after a deploy, so running workers pick up the new code
```

### Queue worker

The roster and LOA syncs and the practice capture run as queued jobs, so nothing syncs without a
worker. That includes the manual syncs: an Admin's **Sync now** and a leader's sync of their own
unit from the roster page. Keep one running and restart it if it exits:

```bash
php artisan queue:work
```

Each job sets its own `$timeout` and retry limit, which take precedence over the worker's
`--timeout` and `--tries`. `SyncRosterJob` and `CaptureSectionPracticeJob` allow 120 seconds,
`SyncLoasJob` 900 seconds (the first run backfills a year of forum threads). `SyncLoasJob` and
`CaptureSectionPracticeJob` get 5 tries; `SyncRosterJob` retries for up to 30 minutes and gives
up after 3 exceptions. The queue's `retry_after` (`DB_QUEUE_RETRY_AFTER` for the database queue)
must be longer than the longest job timeout, so more than 900. If it is shorter, the queue hands a
still-running LOA sync to another worker as if it had died.

### Scheduler

Add one cron entry that runs the Laravel scheduler every minute:

```cron
* * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
```

It queues these jobs (`routes/console.php`; `php artisan schedule:list` shows them):

| Job | Default (UTC) | Env key | Runs only if |
| --- | --- | --- | --- |
| `SyncRosterJob` | `0 * * * *`, hourly on the hour | `ROSTER_SYNC_CRON` | always |
| `SyncLoasJob` (recent threads) | `15 * * * *`, hourly at :15 | `LOA_SYNC_CRON` | `SEVENCAV_FORUM_API_KEY` and `SEVENCAV_FORUM_LOA_NODES` are set |
| `SyncLoasJob` (full run) | `30 3 * * *`, daily at 03:30 | `LOA_SYNC_FULL_CRON` | the same |

Locally, `php artisan schedule:work` runs the scheduler in the foreground; `composer dev` starts a
queue listener but not the scheduler.

### First run

1. Sign in once through 7Cav SSO, so your portal user exists.
2. Make yourself Admin: `php artisan portal:admin <your forum username>`. It refuses a user who
   has never signed in.
3. Seed the course catalog: `php artisan db:seed --class=CourseCatalogSeeder --force`. It needs an
   Admin to record the audited writes against, so it fails until step 2 is done. Re-running it is
   safe; existing course codes are skipped.
4. Import the org structure (Settings → Org structure → Import from Cav), then let the roster sync
   run, or start it with **Sync now** on the roster page.

### Seeders that must not run in production

Only `CourseCatalogSeeder` belongs in production. Never run a bare `db:seed` or `migrate --seed`:

- `DatabaseSeeder` (the default `db:seed` target) and `ReportRollupSeeder` create test users
  (the latter with staff grants); both refuse to run unless `APP_ENV` is `local`, `testing` or `e2e`.
- `ReportScreenSeeder` and `CaptureReviewSeeder` create test users, units and attendance records
  for the browser tests. They have no environment guard, so nothing stops them but you.

## Demo data

To show the portal with history on the **real** roster, generate a few past weeks of plausible
rollcall signatures, Section Practice marks and sent weekly reports. Generated rows are tagged
`is_demo`, never touch troopers, units or users, and skip any week that already holds real records.

```bash
# 1. Switch the demo tools on (off by default; leave them off in production)
echo "DEMO_TOOLS=true" >> .env && php artisan config:clear

# 2. Make yourself Admin (sign in once through 7Cav SSO first). The exact username shows the
#    matched account and asks to confirm; --forum-id skips the prompt (for scripts). An account
#    with no Cav profile linked needs --force; --revoke removes the flag.
php artisan portal:admin <your forum username>
php artisan portal:admin --forum-id=<your forum user id>

# 3. Import the org structure (Settings → Org structure → Import from Cav), then sync the
#    roster so troopers are placed in their sections. The scheduler queues this hourly, but only
#    where `schedule:run` is wired up (see Deploying); `composer dev` doesn't start the scheduler.
#    To run it now:
php artisan tinker --execute="App\Jobs\SyncRosterJob::dispatchSync()"

# 4. Generate 8 completed weeks for a unit's whole subtree (designation or unit id)
php artisan portal:demo-history A/1-7 --weeks=8

# Remove it again (only demo rows are deleted)
php artisan portal:demo-history A/1-7 --purge
```

Each week is one audited transaction. Section reports are sent for every generated week; platoon
and company reports for all but the most recent one, so that week's roll-up can be sent live
during the demo. The current week is never touched. Re-running replaces the earlier demo rows
with the same deterministic pattern. The audit actor and report sender is the earliest Admin, or
pass `--as=<username>`.

A generated section report does not lock its week's rollcall. A real sent report does: once a
section's weekly report for a week is sent, that week's rollcall is read-only. Marking over a demo
week is how a leader makes it real.

The same tools are on **Settings → Demo data** (Admin only, shown only while `DEMO_TOOLS=true`):
pick a unit, choose 1–12 weeks, then Generate or Purge.
