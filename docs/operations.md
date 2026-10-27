# Operations

## In short

The portal is a Laravel app plus a queue worker and the scheduler. The full Docker setup, first install and updates
are in [docker/README.md](../docker/README.md); the non-Docker install, queue and scheduler details are in the main
[README](../README.md#deploying). This page is the checklist.

## Deploy

With Docker Compose (see [docker/README.md](../docker/README.md)):

```bash
git pull
docker compose up -d --build
```

The `app` container runs the migrations and re-caches the config on start, and `queue` and `scheduler` restart on the
new image. After changing only `.env`, run `docker compose up -d`.

Without Docker:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

## After deploy

1. Check the containers are up: `docker compose ps`.
2. Check the schedule is loaded: `php artisan schedule:list` (roster sync hourly, LOA sync hourly at :15 and a full
   run daily at 03:30, the LOA ones only once the forum key and sections are set).
3. If this deploy changes the org import or billet rules (as the HQ staff placement, staff portfolios and acting leads
   change did), run the import step below.

## Import from Cav roster

Needed on a first install and after any release that changes how billets map to units. The HQ staff placement,
portfolio and acting lead release is one: it adds the staff departments, ELOA, Reservists and New Recruits groups, the
portfolio nodes, and the billet mappings that place HQ staff on their HQ node and give portfolio officers their scope.
Until the import runs, none of those exist and those billets give no scope.

1. Sign in as an Admin and open Settings, Org structure.
2. Click "Import from Cav roster". It reads the Cav position groups and, in one transaction:
   - creates missing line units (never renames, moves or deletes existing ones),
   - creates the staff and holding groups and portfolios under their default parents (an Admin's later move of a
     department stands; the import never moves it back),
   - adds billet to unit placements (an existing placement that now disagrees with Cav is counted, not changed),
   - rewrites the billet to command mapping to match Cav exactly (this is what gives leaders their scope).
   The result lists what was created and the billet titles it skipped. Re-running it is safe.
3. Run a roster sync so troopers are placed by the new mappings: "Sync now" on the Roster page, or
   `php artisan portal:sync-roster --now`.
4. On the Org structure page, check the "Troopers the sync could not place" panel and place anyone left there.
5. Assign acting leads where the roster names no lead (for example a `DEVCOM Lead` running DEVCOM starter platoons),
   and set game or team labels on units that need one.

## First install, Admin and seed

```bash
php artisan portal:admin <forum username>      # after that user signed in once; --forum-id=<id> skips the prompt
php artisan db:seed --class=CourseCatalogSeeder --force
```

Never run a bare `db:seed` or `migrate --seed` in production; see the main README for which seeders create test data.

## Useful commands

| Command | What it does |
| --- | --- |
| `php artisan portal:sync-roster [--now]` | Queue (or run now) a whole-roster sync |
| `php artisan portal:sync-loas [--full] [--now]` | Queue (or run now) the LOA sync; refuses while the forum key or sections are unset |
| `php artisan portal:admin <username> [--revoke] [--force]` | Grant or remove the Admin flag (audited) |
| `php artisan portal:demo-history <unit> [--weeks=N] [--purge] [--as=<admin>]` | Demo history, only with `DEMO_TOOLS=true` |

## Env keys that matter

Names only. Every key is documented in `.env.example` and `.env.production.example`. Run `php artisan config:cache`
(or `docker compose up -d`) after a change.

| Area | Keys |
| --- | --- |
| App | `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, `APP_DOMAIN` (Docker only), `TRUSTED_PROXIES` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` (Docker only) |
| Queue and sessions | `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER` (must be above 900), `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE` (leave unset) |
| Sign-in (7cav.us OAuth) | `SEVENCAV_OAUTH_CLIENT_ID`, `SEVENCAV_OAUTH_CLIENT_SECRET`, `SEVENCAV_OAUTH_REDIRECT_URI`, `SEVENCAV_FORUM_BASE_URL`, `SEVENCAV_OAUTH_AUTHORIZE_URL`, `SEVENCAV_OAUTH_SCOPES` |
| Cav roster API | `CAV_API_KEY`, `CAV_API_BASE_URL`, `CAV_API_SWEEP_BUDGET_SECONDS` |
| Leave (LOA) sync | `SEVENCAV_FORUM_API_KEY`, `SEVENCAV_FORUM_LOA_NODES`, `SEVENCAV_FORUM_PAF_NODE`, `LOA_SYNC_REQUEST_DELAY_MS` |
| Practice capture | `CRCON_BASE_URL`, `CRCON_API_KEY`, `BATTLEMETRICS_BASE_URL`, `BATTLEMETRICS_API_KEY`, `BATTLEMETRICS_SERVER_ID`, `NIGHTWATCH_BASE_URL`, `NIGHTWATCH_API_KEY`, `NIGHTWATCH_SERVER_IDS` |
| Portal rules | `PORTAL_WEEK_FLOOR_WEEKS` (default 20), `JSAM_MIN_VISITS` (3), `JSAM_MIN_OWN_SP_PERCENT` (75), `JSAM_WINDOW_WEEKS` (12) |
| Demo | `DEMO_TOOLS` (keep false in production) |

A game with no capture keys set shows "not configured" on the rollcall instead of failing.

## Technical appendix

- Jobs: `SyncRosterJob` and `CaptureSectionPracticeJob` have a 120 second timeout, `SyncLoasJob` 900 seconds. The two syncs
  are unique jobs, so repeated clicks do not stack runs; a capture never runs twice at once for the same practice.
- Each sync run is recorded in `sync_runs`; the pages read the last good run for their "synced at" notes.
- The import is `app/Commands/Units/ImportOrgStructure.php` and takes a cache lock, so a second import at the same time
  is refused.
