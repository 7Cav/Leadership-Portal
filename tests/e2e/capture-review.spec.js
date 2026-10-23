import { expect, test } from '@playwright/test';

// Story 7.8 happy path, against CaptureReviewSeeder: Brandt.K leads a section whose practice this week
// was captured from CRCON, with Okafor.D and Lindqvist.E pre-marked present (auto) and Reyes.J not seen.

test('a section leader reviews a captured practice, overrides an auto mark and confirms it', async ({ page }) => {
    await page.goto('/__e2e/login/Brandt.K?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();

    await expect(page.getByTestId('capture-banner')).toContainText('Marks filled from CRCON, read');
    await expect(page.getByTestId('confirm-badge')).toHaveText('Not confirmed');

    const okafor = page.locator('[data-trooper]').filter({ hasText: 'Okafor.D' });
    const lindqvist = page.locator('[data-trooper]').filter({ hasText: 'Lindqvist.E' });
    const reyes = page.locator('[data-trooper]').filter({ hasText: 'Reyes.J' });
    await expect(okafor.locator('[data-provenance="auto"]')).toBeVisible();
    await expect(lindqvist.locator('[data-provenance="auto"]')).toBeVisible();
    await expect(reyes.locator('[data-provenance="auto"]')).toHaveCount(0);

    // Overriding one auto mark makes it a manual one; the untouched one stays auto.
    await okafor.getByRole('button', { name: /^Missed/ }).click();
    await expect(okafor.locator('[data-provenance="manual"]')).toBeVisible();
    await expect(lindqvist.locator('[data-provenance="auto"]')).toBeVisible();
    await expect(page.getByTestId('save-state')).toContainText('Saved');

    await page.getByTestId('confirm-practice').click();
    await expect(page.getByTestId('confirm-badge')).toHaveText('Confirmed');

    // Both the override and the confirmation are durable.
    await page.reload();
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();
    await expect(page.getByTestId('confirm-badge')).toHaveText('Confirmed');
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Okafor.D' }).getByRole('button', { name: /^Missed/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Okafor.D' }).locator('[data-provenance="manual"]')).toBeVisible();
});

// Decided 2026-10-23: Mbatha.Z (4th Section) was seen on the server during 3rd Section's practice, so the
// capture pre-marked a makeup on his own section's rollcall, shown with the auto source rather than "by hand".
test('a trooper seen at another section\'s practice shows on their own rollcall as an automatic makeup', async ({ page }) => {
    await page.goto('/__e2e/login/Brandt.K?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();

    const mbatha = page.locator('[data-trooper]').filter({ hasText: 'Mbatha.Z' });
    await expect(mbatha.getByRole('button', { name: /^Makeup/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(mbatha.locator('[data-provenance="auto"]')).toBeVisible();
    await expect(mbatha.locator('[data-provenance="makeup"]')).toHaveCount(0);
});
