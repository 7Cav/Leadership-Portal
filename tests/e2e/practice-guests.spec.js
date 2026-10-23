import { expect, test } from '@playwright/test';

// JSAM evidence (decided 2026-10-23), against GuestPracticeSeeder: Osei.K leads 1/1/D/1-7, where he added Faraday.M
// (1/2/D/1-7) as a guest at the last two practices. Osei adds him by hand for this week's practice: he is listed on the
// rollcall as a hand-added guest, and with that third visit and a full own record he shows as a JSAM candidate on the
// section's roster.

test('a section leader adds a guest from another section, who then shows as a JSAM candidate on the roster', async ({ page }) => {
    await page.goto('/__e2e/login/Osei.K?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();

    const panel = page.getByTestId('guests-panel');
    await expect(panel.getByTestId('guest-empty')).toBeVisible();

    await panel.getByTestId('guest-add').click();
    await panel.getByTestId('guest-search').fill('Faraday');
    const result = panel.getByTestId('guest-results').locator('button').filter({ hasText: 'Faraday.M' });
    await expect(result).toContainText('1/2/D/1-7');
    await result.click();

    const row = panel.getByTestId('guest-list').locator('li').filter({ hasText: 'Faraday.M' });
    await expect(row.getByTestId('guest-source')).toHaveText('Added by Osei.K');
    await expect(row.getByTestId('guest-undo')).toBeVisible();

    // Durable: the host rollcall lists him after a reload.
    await page.reload();
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();
    await expect(page.getByTestId('guest-list').locator('li').filter({ hasText: 'Faraday.M' }).getByTestId('guest-source')).toHaveText('Added by Osei.K');

    // The section's roster: three visits in the window, every own practice attended (this week's makeup is not one, and
    // his own section has not practised this week, so it stays out), so a candidate.
    await page.goto('/roster');
    const guests = page.getByTestId('practice-guests');
    const faraday = guests.locator('tr').filter({ hasText: 'Faraday.M' });
    await expect(faraday.getByTestId('practice-guest-visits')).toHaveText('3');
    await expect(faraday.getByTestId('practice-guest-sp')).toHaveText('100%');
    await expect(faraday.getByTestId('jsam-candidate')).toBeVisible();
});
