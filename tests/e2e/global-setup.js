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
    execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seeder=Database\\Seeders\\ReportRollupSeeder', '--seed'], { env, stdio: 'inherit' });
    // The compose screen's own platoon (Story 5.3), apart from the roll-up's so its send cannot
    // change what the roll-up spec counts.
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\ReportScreenSeeder'], { env, stdio: 'inherit' });
    // A captured practice with two unmatched players for the drawer's own spec (Story 7.7).
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\CaptureReviewSeeder'], { env, stdio: 'inherit' });
}
