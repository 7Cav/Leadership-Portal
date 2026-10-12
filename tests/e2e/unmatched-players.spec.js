import { expect, test } from '@playwright/test';

// Story 7.7 happy path, against CaptureReviewSeeder: Hartmann.L leads a section whose practice this
// week was captured with two players no trooper is linked to ("Dubes" and "RandoGuy88").

test('a section leader links one unmatched player to a trooper and dismisses another', async ({ page }) => {
    await page.goto('/__e2e/login/Hartmann.L?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();

    await expect(page.getByTestId('unmatched-banner')).toContainText('2 players need a trooper');
    const drawer = page.getByTestId('unmatched-drawer');
    await expect(drawer).toBeVisible();

    // Link stays disabled until the leader picks a trooper; the closest name is offered, not chosen.
    const dubes = page.getByTestId('unmatched-76561198000009001');
    await expect(dubes.getByTestId('unmatched-link')).toBeDisabled();
    await expect(dubes.getByTestId('unmatched-trooper')).toHaveValue('');
    await dubes.getByTestId('unmatched-trooper').selectOption({ label: 'PFC Dube.R' });
    await expect(dubes.getByTestId('unmatched-link')).toBeEnabled();
    await dubes.getByTestId('unmatched-link').click();

    await expect(dubes).toHaveCount(0);
    await expect(page.getByTestId('unmatched-banner')).toContainText('1 player needs a trooper');
    const dubeCard = page.locator('[data-trooper]').filter({ hasText: 'Dube.R' });
    await expect(dubeCard.getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');

    const rando = page.getByTestId('unmatched-76561198000009002');
    await rando.getByTestId('unmatched-dismiss').click();
    await rando.getByTestId('confirm-dismiss').click();

    await expect(drawer).toHaveCount(0);
    await expect(page.getByTestId('unmatched-banner')).toHaveCount(0);

    // Both resolutions are durable: nothing is listed after a reload, and the mark came from the server.
    await page.reload();
    await expect(page.getByTestId('unmatched-drawer')).toHaveCount(0);
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Dube.R' }).getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');
});
