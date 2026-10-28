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
   run daily at 03:30, the LOA ones only once the forum key and sections are set; the activity log prune daily at
   04:15).
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
| `php artisan portal:prune-page-visits` | Delete activity log page visits older than 30 days (scheduled daily) |
| `php artisan portal:demo-history <unit> [--weeks=N] [--purge] [--as=<admin>]` | Demo history, only with `DEMO_TOOLS=true` |

## Env keys that matter

Names only. Every key is documented in `.env.example` and `.env.production.example`. Run `php artisan config:cache`
(or `docker compose up -d`) after a change.

| Area | Keys |
| --- | --- |
| App | `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, `APP_DOMAIN` (Docker only), `TRUSTED_PROXIES` |
| Security headers | `CSP_REPORT_ONLY` (false: the content security policy is enforced; see below) |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` (Docker only) |
| Queue and sessions | `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER` (must be above 900), `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE` (leave unset) |
| Sign-in (7cav.us OAuth) | `SEVENCAV_OAUTH_CLIENT_ID`, `SEVENCAV_OAUTH_CLIENT_SECRET`, `SEVENCAV_OAUTH_REDIRECT_URI`, `SEVENCAV_FORUM_BASE_URL`, `SEVENCAV_OAUTH_AUTHORIZE_URL`, `SEVENCAV_OAUTH_SCOPES` |
| Cav roster API | `CAV_API_KEY`, `CAV_API_BASE_URL`, `CAV_API_SWEEP_BUDGET_SECONDS` |
| Leave (LOA) sync | `SEVENCAV_FORUM_API_KEY`, `SEVENCAV_FORUM_LOA_NODES`, `SEVENCAV_FORUM_PAF_NODE`, `LOA_SYNC_REQUEST_DELAY_MS` |
| Practice capture | `CRCON_BASE_URL`, `CRCON_API_KEY`, `BATTLEMETRICS_BASE_URL`, `BATTLEMETRICS_API_KEY`, `BATTLEMETRICS_SERVER_ID`, `NIGHTWATCH_BASE_URL`, `NIGHTWATCH_API_KEY`, `NIGHTWATCH_SERVER_IDS` |
| Counseling documents to Google Drive | `GOOGLE_DRIVE_SERVICE_ACCOUNT_EMAIL`, `GOOGLE_DRIVE_PRIVATE_KEY`, `GOOGLE_DRIVE_FOLDER_ID` (see below) |
| Portal rules | `PORTAL_WEEK_FLOOR_WEEKS` (default 20), `JSAM_MIN_VISITS` (3), `JSAM_MIN_OWN_SP_PERCENT` (75), `JSAM_WINDOW_WEEKS` (12) |
| Demo | `DEMO_TOOLS` (keep false in production) |

Which of these a game uses is set per game on Settings > Games. A game whose interface has no keys set (or is set to
none) shows "not configured" on the rollcall instead of failing.

## Counseling documents and Google Drive

DCS and LOR documents are PDFs kept on the private `counseling` disk, `storage/app/counseling` (inside the `storage`
volume in Docker, outside `public/`). Back that folder up with the database: a record without its file downloads as
not found. The portal never serves the folder directly; a document leaves only through its record's download route. Without Docker,
set PHP `upload_max_filesize` and `post_max_size` to at least 11M so a 10 MB PDF can be uploaded (the Docker image allows
100M).

To copy each new document to the 7Cav Google Drive as well:

1. In Google Cloud, create a service account (no roles needed) and a JSON key for it.
2. Put the folder in a shared drive and add the service account's email to the shared drive (or the folder) as
   Content manager. A service account has no storage of its own, so a folder in someone's My Drive does not work.
3. Set `GOOGLE_DRIVE_SERVICE_ACCOUNT_EMAIL` to the key's `client_email`, `GOOGLE_DRIVE_PRIVATE_KEY` to its
   `private_key` on one line (line breaks written as `\n`, as in the JSON), and `GOOGLE_DRIVE_FOLDER_ID` to the last
   part of the folder's URL. Then `docker compose up -d` (or `php artisan config:cache`).

The copy runs on the queue (`UploadCounselingDocumentJob`), up to 5 tries. With any of the three keys unset nothing is
sent and records show "Portal only". A record that shows "Drive copy failed" after the last try needs its document
uploaded by hand; the queue worker's log names the record id and why, never the key. The portal only uploads: it
cannot read, share or delete anything on the Drive, so deleting a record in the portal leaves the Drive copy.

## Content security policy

Every response carries a `Content-Security-Policy` that lists what a page may load (`SetSecurityHeaders`, sources
in `config/csp.php`). With the default config it reads, on one line:

```
default-src 'self'; script-src 'self' 'nonce-<per request>'; style-src 'self' 'unsafe-inline';
img-src 'self' data: https://7cav.us https://*.7cav.us; font-src 'self'; connect-src 'self'; object-src 'none';
base-uri 'self'; form-action 'self'; frame-ancestors 'none'
```

- Scripts come only from the portal: the built bundles, and the inline theme script and Vite's tags by a nonce that
  changes on every request. Inline styles are allowed, since `<style>` elements are added at runtime (Inertia's
  progress bar) and the framework's plain error pages carry their own; a style cannot run code.
- Images: the portal, `data:` (the guidon) and 7cav.us with its subdomains (rank insignia, BBCode preview images).
- Sign-in is a link to `/auth/redirect`, which redirects to 7cav.us, and sign-out posts to the portal, so
  `form-action 'self'` is enough.
- While `npm run dev` runs on a local machine (`APP_ENV=local` and `public/hot` present), the Vite dev server's
  origin and websocket are allowed as well. Nowhere else.
- A debug error page (`APP_DEBUG=true` and a 5xx) only gets `frame-ancestors 'none'`, so the framework's error page
  still works on a dev box.

To allow another host, add it to the directive that needs it in `config/csp.php` and run `php artisan config:cache`
(or restart the containers). If a page looks broken after a change, the browser console names the blocked source
("Refused to load ...").

Rolling out: the policy ships enforced, and the browser suite fails on any violation. To watch it for a release
first, set `CSP_REPORT_ONLY=true`: browsers then log what they would block in the console and block nothing, while
`frame-ancestors 'none'` stays enforced. There is no report endpoint, so check the console on the main pages, then
set it back to false.

The same middleware sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`,
`Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy` (plus HSTS in production over HTTPS). It runs first,
so the maintenance page, a 413 for an upload over the size limit and CORS preflights carry them too. Two known gaps:

- A page pre-rendered with `php artisan down --render` is served before the app starts, without these headers.
- The `/up` health page shows unstyled: the framework's page loads its styles from a CDN, which the policy blocks. The
  status code is unchanged, so the container health check still passes. Leave the CDN out of the policy.

## Technical appendix

- Jobs: `SyncRosterJob`, `CaptureSectionPracticeJob` and `UploadCounselingDocumentJob` have a 120 second timeout, `SyncLoasJob` 900 seconds. The two syncs
  are unique jobs, so repeated clicks do not stack runs; a capture never runs twice at once for the same practice.
- Each sync run is recorded in `sync_runs`; the pages read the last good run for their "synced at" notes.
- The import is `app/Commands/Units/ImportOrgStructure.php` and takes a cache lock, so a second import at the same time
  is refused.
