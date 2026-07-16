import { defineConfig, devices } from '@playwright/test';

// Playwright browser E2E (Story 1.3). Boots the real Laravel app and drives a real
// browser against it. Assets must be built first (`npm run build`) so @vite resolves
// the manifest; CI does that in a prior step. The sample page touches no DB, so a
// MySQL service isn't required — the app serves on SQLite in CI.
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
