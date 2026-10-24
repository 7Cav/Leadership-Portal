import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { E2E_DATABASE, E2E_ENV } from './env.js';

// A fresh, seeded database for every Playwright run (see env.js). Playwright starts the web server
// before this runs; that is fine because `artisan serve` opens the database per request, and no
// test request is made until setup has finished.
export default function globalSetup() {
    writeFileSync(E2E_DATABASE, '');
    const env = { ...process.env, ...E2E_ENV };
    execFileSync('php', ['artisan', 'config:clear'], { env, stdio: 'inherit' });
    // Fail closed before anything destructive: blanking known variables (DB_URL) is not enough, since
    // DB_HOST, DATABASE_URL, DB_SOCKET and whatever comes next could also redirect the connection.
    // Abort unless the connection the app will actually use is the throwaway SQLite file.
    const target = JSON.parse(execFileSync('php', ['artisan', 'db:show', '--json'], { env, encoding: 'utf8' }));
    const config = target.platform?.config;
    if (config?.driver !== 'sqlite' || config?.database !== E2E_DATABASE) {
        throw new Error(`Refusing to run E2E setup: the app resolves its database to ${config?.database} (${config?.driver}), not ${E2E_DATABASE}.`);
    }
    execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seeder=Database\\Seeders\\ReportRollupSeeder', '--seed'], { env, stdio: 'inherit' });
    // The compose screen's own platoon (Story 5.3), apart from the roll-up's so its send cannot
    // change what the roll-up spec counts.
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\ReportScreenSeeder'], { env, stdio: 'inherit' });
    // A captured practice with two unmatched players for the drawer's own spec (Story 7.7).
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\CaptureReviewSeeder'], { env, stdio: 'inherit' });
    // Sections with nobody marked yet, one per way of leaving with marks held for Undo.
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\RollcallHeldMarksSeeder'], { env, stdio: 'inherit' });
    // A guest added by hand at two of a section's practices, for the JSAM guests spec (2026-10-23).
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\GuestPracticeSeeder'], { env, stdio: 'inherit' });
    // Staff, ELOA and New Recruit groups on the org page (2026-10-24).
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\OrgPlacementSeeder'], { env, stdio: 'inherit' });
}
