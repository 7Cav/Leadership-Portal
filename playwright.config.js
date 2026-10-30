import { defineConfig, devices } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { E2E_ENV } from './tests/e2e/env.js';

// The test sign-in route accepts only this run's local browser. The cookie is scoped to
// 127.0.0.1 and HttpOnly, so it is not sent to third-party sites or readable by page scripts.
// Every worker process loads this config again, so the token is drawn once in the runner and
// handed down through the environment; a fresh one per worker would never match the server's.
process.env.E2E_LOGIN_TOKEN ||= randomBytes(32).toString('hex');
const e2eLoginToken = process.env.E2E_LOGIN_TOKEN;

// Playwright browser E2E (Story 1.3). Boots the real Laravel app and drives a real
// browser against it. Assets must be built first (`npm run build`) so @vite resolves
// the manifest; CI does that in a prior step. The server runs on a throwaway SQLite database that
// global-setup migrates and seeds fresh (tests/e2e/env.js), so no MySQL service is needed.
export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.js',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8010',
        storageState: {
            cookies: [{
                name: 'e2e_login_token',
                value: e2eLoginToken,
                domain: '127.0.0.1',
                path: '/',
                expires: -1,
                httpOnly: true,
                secure: false,
                sameSite: 'Strict',
            }],
            origins: [],
        },
        trace: 'on-first-retry',
    },
    projects: [
        // Every spec shares the one seeded database and runs in parallel, so a spec that changes a game's settings for
        // everyone (the ID-kinds one) runs on its own, after the rest, rather than beside flows that read that game.
        { name: 'chromium', testIgnore: /game-id-kinds\.spec\.js/, use: { ...devices['Desktop Chrome'] } },
        { name: 'chromium-shared-settings', testMatch: /game-id-kinds\.spec\.js/, dependencies: ['chromium'], use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        // Its own port and environment (tests/e2e/env.js), so a dev server on :8000 is never
        // mistaken for it and the seeded e2e database is the one served. `--no-reload` passes the
        // environment through to the PHP server instead of re-reading .env over it.
        command: 'php artisan serve --host=127.0.0.1 --port=8010 --no-reload',
        url: 'http://127.0.0.1:8010',
        env: { ...E2E_ENV, E2E_LOGIN_TOKEN: e2eLoginToken },
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
