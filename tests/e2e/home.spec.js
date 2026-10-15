import { test, expect } from '@playwright/test';

// Stories 5.9 to 5.11: the Home dashboard, against the shared E2E seed (ReportRollupSeeder):
// A/1-7 with two platoons and eight sections, five sent this week, two drafts, one not started.
// Hale.S commands the company; Vasquez.R leads 2/1/A/1-7, one of the drafts. Read-only.

test('a company commander reads the dashboard and drills into a report', async ({ page }) => {
    await page.goto('/__e2e/login/Hale.S?to=/home');

    await expect(page.getByRole('heading', { name: /^Week of / })).toBeVisible();

    // KPI tiles: the six canonical KPIs, with history from last week's sent snapshots.
    const tiles = page.getByTestId('kpi-tiles');
    await expect(tiles.locator('[data-kpi]')).toHaveCount(6);
    await expect(tiles.locator('[data-kpi="strength"] dd span').first()).toHaveText('47');
    await expect(page.getByTestId('roster-freshness')).toBeVisible();

    // Needs attention: the three sections still holding up the chain.
    const worklist = page.getByTestId('needs-attention');
    await expect(worklist.locator('[data-item="holding"]')).toHaveCount(3);
    await expect(worklist.locator('[data-item="holding"]').first()).toContainText('2/1/A/1-7');

    // Step matrix: a row per section, each step a link to the screen that does it.
    const matrix = page.getByTestId('step-matrix');
    await expect(matrix.locator('tbody tr')).toHaveCount(8);

    // Subordinate units: both platoons, each holding on its sections.
    const units = page.getByTestId('subordinate-units');
    await expect(units.locator('tbody tr')).toHaveCount(2);
    await expect(units.getByRole('row', { name: /1\/A\/1-7/ }).locator('[data-status]')).toHaveText('Holding');

    await units.getByRole('link', { name: '1/A/1-7', exact: true }).first().click();
    await expect(page).toHaveURL(/\/reports\/\d+\/\d{4}-\d{2}-\d{2}$/);
});

test('a section leader sees only their own section', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/home');

    await expect(page.getByTestId('kpi-tiles').locator('[data-kpi="strength"] dd span').first()).toHaveText('8');
    await expect(page.getByTestId('subordinate-units').getByRole('heading', { name: 'Your section' })).toBeVisible();
    await expect(page.getByTestId('subordinate-units').locator('tbody tr')).toHaveCount(1);
    await expect(page.getByTestId('step-matrix').locator('tbody tr')).toHaveCount(1);

    await page.getByTestId('step-matrix').locator('[data-step="posted"]').click();
    await expect(page).toHaveURL(/\/rollcall\/\d+\?week=\d{4}-\d{2}-\d{2}&step=3$/);
    await expect(page.getByRole('heading', { name: 'Review and post', level: 2 })).toBeVisible();
});
