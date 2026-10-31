import { test, expect } from '@playwright/test';

// Story 5.8: a company commander reviews the week's reports in the roll-up workspace. Data comes
// from ReportRollupSeeder: A/1-7 with eight sections, five sent, two drafts, one not started.

test('a commander walks the chain, opens a report and copies a reminder', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/__e2e/login/Hale.S?to=/reports');

    await expect(page.getByRole('heading', { name: /^Week Ending / })).toBeVisible();
    await expect(page.getByTestId('progress-label')).toHaveText('Sections sent 5 of 8');

    const tree = page.getByRole('treegrid');
    // "Not sent" is the default filter: sent sections are hidden, the unsent ones and their chain show.
    await expect(tree.getByRole('row', { name: /2\/2\/A\/1-7/ })).toBeVisible();
    await expect(tree.getByRole('row', { name: /1\/1\/A\/1-7/ })).toHaveCount(0);

    await page.getByTestId('filter-all').click();
    const sent = tree.getByRole('row', { name: /1\/1\/A\/1-7/ });
    await expect(sent).toBeVisible();

    // Keyboard: focus the company row, arrow down into the first platoon, Enter opens its report.
    await tree.getByRole('row').first().focus();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    const panel = page.getByTestId('report-panel');
    await expect(panel.getByRole('heading', { name: '1/A/1-7' })).toBeVisible();
    await expect(panel.getByTestId('holding')).toContainText('Waiting on');

    await sent.click();
    await expect(panel.getByRole('heading', { name: '1/1/A/1-7' })).toBeVisible();
    await expect(panel).toContainText('Frozen snapshot');
    await expect(panel).toContainText('Full house for the AT drill.');

    await tree.getByRole('row', { name: /2\/1\/A\/1-7/ }).click();
    await expect(panel.getByTestId('live-label')).toContainText('Live draft, not sent');
    await expect(panel.getByTestId('open-report')).toHaveAttribute('href', /^\/reports\/\d+\/\d{4}-\d{2}-\d{2}$/);

    await panel.getByTestId('copy-reminder').click();
    await expect(page.getByText('Reminder copied')).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toMatch(/^Reminder: the 2nd Section \(2\/1\/A\/1-7\) weekly report for the week ending \d{2}\w{3}\d{2} /);
});

test('a section leader lands on their own report', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/reports');

    await expect(page).toHaveURL(/\/reports\/\d+\/\d{4}-\d{2}-\d{2}$/);
});
