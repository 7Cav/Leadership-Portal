import { test, expect } from '@playwright/test';

// Stories 5.3–5.5: a section leader opens this week's draft, clears the checklist, and freezes
// and sends it through the pre-send overview. Data from ReportScreenSeeder: Mercer.J leads
// 2/1/B/1-7, whose week leaves one trooper unmarked, so the send goes through "Send anyway" with
// the open item in the reason. The roll-up workspace sends a single-section leader straight here.
test('a section leader completes and sends the weekly report', async ({ page }) => {
    await page.goto('/__e2e/login/Mercer.J?to=/reports');

    await expect(page).toHaveURL(/\/reports\/\d+\/\d{4}-\d{2}-\d{2}$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Week of');
    await expect(page.getByTestId('status-badge')).toHaveText('Draft');
    await expect(page.getByTestId('kpi-grid')).toBeVisible();
    // The rollcall figure and the headcount with staff, labelled apart (#424).
    await expect(page.getByTestId('kpi-grid').locator('[data-kpi="strength"]')).toContainText('In roll call');
    await expect(page.getByTestId('kpi-grid').locator('[data-kpi="strength"]')).toContainText(/Headcount \d+, with staff/);

    await page.getByLabel(/^Reason for .*Kowalczyk\.P$/).selectOption({ label: 'Sick' });
    await page.getByLabel('Training subject').fill('Room clearing, two-man entries');
    await expect(page.getByTestId('save-state')).toContainText('Draft saved');
    await page.getByLabel('I have read the preview and my notes as command will see them').check();

    await expect(page.getByTestId('send-bar')).toContainText('1 check left');
    await page.getByTestId('send-anyway').click();

    const dialog = page.getByTestId('send-dialog');
    await expect(dialog).toContainText('Still open: practice marked.');
    await expect(dialog.getByLabel('Reason for the record')).toHaveValue('Weekly report, sent with open items: practice marked');
    await dialog.getByTestId('send-confirm').click();

    await expect(page.getByTestId('status-badge')).toHaveText('Sent');
    await expect(page.getByTestId('sent-line')).toContainText('by SGT Mercer.J');
    await expect(page.getByTestId('sent-panel')).toContainText('Room clearing, two-man entries');

    // Reloading shows the frozen snapshot, read-only.
    await page.reload();
    await expect(page.getByLabel('Training subject')).toBeDisabled();
    await expect(page.getByTestId('send-bar')).toHaveCount(0);
});
