import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { E2E_DATABASE, E2E_ENV } from './env.js';

// A fresh, seeded database for every Playwright run (see env.js).
export default function globalSetup() {
    writeFileSync(E2E_DATABASE, '');
    const env = { ...process.env, ...E2E_ENV };
    execFileSync('php', ['artisan', 'config:clear'], { env, stdio: 'inherit' });
    execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seeder=Database\\Seeders\\ReportRollupSeeder', '--seed'], { env, stdio: 'inherit' });
}
