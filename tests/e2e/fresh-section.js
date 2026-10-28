import { execFileSync } from 'node:child_process';
import { randomBytes, randomInt } from 'node:crypto';
import { E2E_ENV } from './env.js';

// A section of its own for one test (GameIdentitySectionSeeder): eight troopers linked to no game, under a new leader
// whose username comes back. Seeded straight into the e2e database the way global-setup seeds, so a test, its retries
// and its --repeat-each copies each start clean and never share links with a test on another worker.
export function freshSection() {
    const leader = `Lead${randomBytes(4).toString('hex')}.G`;
    execFileSync('php', ['artisan', 'db:seed', '--force', '--class=Database\\Seeders\\GameIdentitySectionSeeder'], {
        env: { ...process.env, ...E2E_ENV, E2E_SECTION_LEADER: leader },
        stdio: 'pipe',
    });

    return leader;
}

// A Steam ID's shape (17 digits starting 7656119) no other test holds: a game's player IDs are unique across every
// trooper, so a fixed one would collide with the same test running in another section.
export function steamId() {
    return `7656119${String(randomInt(0, 10_000_000_000)).padStart(10, '0')}`;
}
