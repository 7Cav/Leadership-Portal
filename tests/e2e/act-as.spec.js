import { test, expect } from '@playwright/test';

// #432: a leader acts as a serving billet holder below them, against the shared E2E seed (ReportRollupSeeder): Hale.S
// commands Alpha Company; Vasquez.R leads 2/1/A/1-7 under it. Nothing is written but the audit entries for starting and
// stopping, and the spec stops acting before it ends.

test('a company commander acts as a section leader under the red bar, then stops', async ({ page }) => {
    await page.goto('/__e2e/login/Hale.S?to=/home');
    await expect(page.getByTestId('acting-as-banner')).toHaveCount(0);

    await page.getByTestId('user-menu').click();
    await page.getByTestId('act-as').click();

    // The picker lists the billet holders below the commander, never the commander.
    const picker = page.getByTestId('act-as-picker');
    await expect(picker.getByTestId('act-as-list')).toBeVisible();
    await expect(picker).toContainText('Vasquez.R');
    await expect(picker).not.toContainText('Hale.S');
    await expect(picker).not.toContainText('Pike.S');

    await picker.getByText('Vasquez.R').click();
    await picker.getByTestId('act-as-picker-confirm-button').click();

    const bar = page.getByTestId('acting-as-banner');
    await expect(bar).toBeVisible();
    await expect(bar).toContainText('Acting as');
    await expect(bar).toContainText('Vasquez.R');
    await expect(bar).toContainText('Hale.S');

    // As the section leader the picker is not offered: no chains.
    await page.getByTestId('user-menu').click();
    await expect(page.getByTestId('act-as')).toHaveCount(0);
    await page.keyboard.press('Escape');

    await page.getByTestId('acting-as-stop').click();
    await expect(page.getByTestId('acting-as-banner')).toHaveCount(0);
});
