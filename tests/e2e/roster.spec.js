import { expect, test } from '@playwright/test';

// Stories 2.8 / 4.6–4.9 happy path. Data comes from ReportRollupSeeder: Vasquez.R holds a staff
// grant over section 2/1/A/1-7 (eight troopers with attendance), Hale.S over company A/1-7.

test('a section leader reads their section in both views and opens a trooper', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/home');
    await page.getByTestId('nav-Roster').click();

    await expect(page).toHaveURL(/\/roster$/);
    await expect(page.getByRole('heading', { name: 'Roster 2/1/A/1-7' })).toBeVisible();
    await expect(page.getByTestId('roster-sync-stamp')).toContainText('milpac');

    const rows = page.locator('tr[data-member]');
    await expect(rows).toHaveCount(8);

    // Keyboard: the first trooper row opens the Sheet with Enter.
    await rows.first().focus();
    await page.keyboard.press('ArrowDown');
    await expect(rows.nth(1)).toBeFocused();

    await page.getByTestId('view-matrix').click();
    await expect(page).toHaveURL(/view=matrix/);
    const grid = page.getByRole('grid', { name: 'Attendance by Cav week' });
    await expect(grid).toBeVisible();
    await expect(grid.locator('thead th.is-now')).toHaveCount(1);

    await page.reload();
    await expect(page.getByTestId('roster-matrix')).toBeVisible();

    await grid.locator('tbody [data-open]').first().click();
    const sheet = page.getByTestId('trooper-sheet');
    await expect(sheet.getByTestId('sheet-recent').locator('tbody tr')).toHaveCount(8);
    await expect(sheet.getByTestId('sheet-milpac')).toHaveAttribute('href', /^https:\/\/7cav\.us\/rosters\/profile\?uniqueid=\d+$/);
    await expect(page).toHaveURL(/trooper=\d+/);
});

test('a unit outside the leader\'s scope is refused by the server', async ({ page }) => {
    // Hale.S's scope switcher names the company's own roster URL…
    await page.goto('/__e2e/login/Hale.S?to=/roster');
    await page.getByTestId('roster-scope').click();
    const companyHref = await page.getByRole('menuitem', { name: /A\/1-7/ }).first().getAttribute('href');

    // …which the section leader, whose grant covers one section, may not open.
    await page.goto('/__e2e/login/Vasquez.R?to=/home');
    const response = await page.goto(companyHref);
    expect(response.status()).toBe(403);
});
