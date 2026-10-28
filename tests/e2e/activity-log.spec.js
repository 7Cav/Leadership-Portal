import { test, expect } from '@playwright/test';

// #382 browser check: an Admin (Pike.S, from ReportRollupSeeder) opens a page, then finds that visit in Settings,
// Activity log, with its sensitive query parameter dropped, and filters the log by user. A section leader is refused.

test('an Admin sees the pages they opened in the activity log, filterable by user', async ({ page }) => {
    await page.goto('/__e2e/login/Pike.S?to=/roster?probe=activity-log%26token=e2e-secret');
    await expect(page).toHaveURL(/\/roster\?probe=activity-log/);

    // An Inertia navigation from the sidebar, which is itself a recorded visit.
    await page.getByTestId('nav-Activity log').click();
    await expect(page.getByRole('heading', { name: 'Activity log' })).toBeVisible();
    await expect(page).toHaveTitle('Settings · Activity log · 7Cav Leadership Portal');

    const visit = page.locator('[data-testid^="visit-row-"]').filter({ hasText: '/roster?probe=activity-log' }).first();
    await expect(visit).toContainText('Pike.S');
    await expect(visit.getByTestId('visit-url')).toHaveText('/roster?probe=activity-log');
    await expect(page.getByText('e2e-secret')).toHaveCount(0);

    await page.getByTestId('filter-user_id').selectOption({ label: 'Pike.S' });
    await page.getByTestId('apply-filters').click();
    await expect(page).toHaveURL(/user_id=\d+/);

    const rows = page.locator('[data-testid^="visit-row-"]');
    await expect(rows.first()).toBeVisible();
    for (const text of await rows.allInnerTexts()) expect(text).toContain('Pike.S');
});

test('a section leader is refused the activity log', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/home');

    await expect(page.getByTestId('nav-Activity log')).toHaveCount(0);
    const response = await page.goto('/settings/activity-log');
    expect(response.status()).toBe(403);
});
