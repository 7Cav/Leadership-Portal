import { expect, test } from '@playwright/test';

// Decided 2026-10-27: after a transfer the new leadership sees the trooper's history from before the move. Against
// TransferHistorySeeder: Okafor.B leads 1/1/E/1-7; Brandt.L signed and attended 2/1/E/1-7's practices two and three
// weeks ago and moved to Okafor's section last week.

test('a section leader sees a transferred trooper\'s weeks at the old section and the move, on the Sheet and the milpac', async ({ page }) => {
    await page.goto('/__e2e/login/Okafor.B?to=/roster');
    await expect(page.getByRole('heading', { name: 'Roster 1/1/E/1-7' })).toBeVisible();

    await page.locator('tr[data-member]').filter({ hasText: 'Brandt.L' }).click();
    const sheet = page.getByTestId('trooper-sheet');
    await expect(sheet.getByTestId('transfers')).toContainText(/Moved from 2\/1\/E\/1-7 to 1\/1\/E\/1-7, \d{2}[A-Z][a-z]{2}\d{2}/);
    await expect(sheet.getByTestId('sheet-week-section')).toHaveCount(2);
    await expect(sheet.getByTestId('sheet-week-section').first()).toHaveText('At 2/1/E/1-7');
    // Both weeks at the old section count: two of two practices attended.
    await expect(sheet.getByText('2 of 2 attended')).toBeVisible();

    await sheet.getByTestId('sheet-open-trooper').click();
    await expect(page).toHaveURL(/\/troopers\/\d+$/);
    const panel = page.getByTestId('attendance-panel');
    await expect(panel.getByTestId('transfers')).toContainText('Moved from 2/1/E/1-7 to 1/1/E/1-7');
    const oldWeeks = panel.getByTestId('attendance-week').filter({ has: page.getByTestId('attendance-section').getByText('2/1/E/1-7', { exact: true }) });
    await expect(oldWeeks.filter({ hasText: /Attended/ })).toHaveCount(2);
    await expect(oldWeeks.filter({ hasText: 'Will attend' })).toHaveCount(2);
});
