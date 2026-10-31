import { test, expect } from '@playwright/test';

// #380: a platoon leader writes the month's training plan, the company commander sends it back with a comment, the
// leader changes it and the commander approves it, which puts the first week's subject on the platoon's report. Data
// from TrainingPlanSeeder: Navarro.D leads 1/F/1-7, Kerr.A commands F/1-7.
test('a platoon leader plans the month and the company commander sends it back, then approves it', async ({ page }) => {
    await page.goto('/__e2e/login/Navarro.D?to=/reports');

    // From the roll-up to the platoon's report, and from there to its plan for that week's month.
    await page.getByRole('treegrid').getByRole('row').first().click();
    const panel = page.getByTestId('report-panel');
    await expect(panel.getByRole('heading', { name: '1/F/1-7' })).toBeVisible();
    await panel.getByTestId('open-report').click();
    await expect(page).toHaveURL(/\/reports\/\d+\/\d{4}-\d{2}-\d{2}$/);
    await page.getByTestId('training-plan-link').click();
    await expect(page).toHaveURL(/\/reports\/\d+\/plans\/\d{4}-\d{2}$/);
    const planPath = new URL(page.url()).pathname;

    await expect(page.getByTestId('plan-status')).toHaveText('Not written');
    await page.getByLabel(/^Week Ending /).nth(0).fill('Room clearing, two-man entries');
    await page.getByLabel('Lock these subjects, so sections cannot change them').check();
    await page.getByTestId('plan-send').click();
    await expect(page.getByTestId('plan-status')).toHaveText('Waiting for approval');
    await expect(page.getByTestId('plan-history')).toContainText('Written by SFC Navarro.D');

    // The company commander sends it back with a comment.
    await page.goto(`/__e2e/login/Kerr.A?to=${planPath}`);
    const review = page.getByTestId('plan-review');
    await expect(review).toContainText('Review for F/1-7');
    await expect(review.getByTestId('plan-return')).toBeDisabled();
    await review.getByLabel('What should change').fill('Add radio procedure in week two');
    await review.getByTestId('plan-return').click();
    await expect(page.getByTestId('plan-status')).toHaveText('Sent back');
    await expect(page.getByTestId('plan-review')).toHaveCount(0);

    // The leader sees why and sends a changed plan.
    await page.goto(`/__e2e/login/Navarro.D?to=${planPath}`);
    await expect(page.getByTestId('plan-comment')).toHaveText('Add radio procedure in week two');
    await expect(page.getByTestId('plan-review')).toHaveCount(0);
    await page.getByLabel(/^Week Ending /).nth(1).fill('Radio procedure');
    await page.getByTestId('plan-send').click();
    await expect(page.getByTestId('plan-status')).toHaveText('Waiting for approval');

    // The commander approves it, and the first week's subject is now the platoon's topic, locked.
    await page.goto(`/__e2e/login/Kerr.A?to=${planPath}`);
    await page.getByTestId('plan-approve').click();
    await expect(page.getByTestId('plan-status')).toHaveText('Approved');
    await expect(page.getByTestId('plan-history')).toContainText('Approved by CPT Kerr.A');

    await page.getByRole('link', { name: 'Open report' }).first().click();
    await expect(page.getByLabel('Training topic for your sections')).toHaveValue('Room clearing, two-man entries');
    await expect(page.getByTestId('topic-status')).toContainText('Locked on every section report this week.');
});
