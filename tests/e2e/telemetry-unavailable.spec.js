import { expect, test } from '@playwright/test';

// Story 7.9 happy path, against CaptureReviewSeeder: Voss.H leads a section whose practice this week
// was never captured because the game server could not be read (a failed capture run, three hours ago).
// The screen says so with the time, offers the unchanged manual rollcall, and "Try again" asks again.

test('a practice whose last capture failed shows the unavailable banner, the manual screen and Try again', async ({ page }) => {
    await page.goto('/__e2e/login/Voss.H?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();

    const banner = page.getByTestId('telemetry-banner');
    // The banner names the feed that is down (#176), so it never reads as an empty server.
    await expect(banner).toContainText('Hell Let Loose server (CRCON) could not be read, last tried');
    // The Zulu time carries a "(3 h ago)" note after it.
    await expect(banner).toContainText(/UTC \([^)]*ago\)\. Nothing was pre-filled\. Mark attendance by hand\./);
    const lastTried = await banner.innerText();

    // The manual screen is unchanged: no capture banner, no source dots, no unmatched panel.
    await expect(page.getByTestId('capture-banner')).toHaveCount(0);
    await expect(page.getByTestId('unmatched-drawer')).toHaveCount(0);
    await expect(page.locator('[data-provenance="auto"]')).toHaveCount(0);

    // Marking by hand works and is confirmed the same way.
    const park = page.locator('[data-trooper]').filter({ hasText: 'Park.J' });
    await park.getByRole('button', { name: /^Present/ }).click();
    await expect(park.locator('[data-provenance="manual"]')).toBeVisible();
    await expect(page.getByTestId('save-state')).toContainText('Saved');

    // Try again: the server is still down, and the screen says so with a new time rather than going quiet.
    await page.getByTestId('telemetry-retry').click();
    await expect(page.getByText('Hell Let Loose server (CRCON) still could not be read')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('telemetry-banner')).not.toHaveText(lastTried);
    await expect(page.getByTestId('telemetry-banner')).toContainText('Hell Let Loose server (CRCON) could not be read');
    await expect(park.getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');

    await page.getByTestId('confirm-practice').click();
    await expect(page.getByTestId('confirm-badge')).toHaveText('Confirmed');
});
