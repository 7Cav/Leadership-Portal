import { expect, test } from '@playwright/test';

// Story 7.7 happy path, against CaptureReviewSeeder: Hartmann.L leads a section whose practice this
// week was captured with three players no trooper is linked to ("Dubes", "RandoGuy88" and "Halvorsen";
// Halvorsen.K already holds another HLL ID, so linking them asks to replace or cancel, #176).

test('a section leader links unmatched players to troopers, replacing an old ID only when asked, and dismisses another', async ({ page }) => {
    await page.goto('/__e2e/login/Hartmann.L?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    // The count shows in the stepper from whichever step the rollcall opens on (#176).
    const count = page.getByTestId('unlinked-count');
    await expect(count).toHaveText('3 unlinked players');
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();

    await expect(page.getByTestId('unmatched-banner')).toContainText('3 players need a trooper');
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
    await expect(page.getByTestId('unmatched-banner')).toContainText('2 players need a trooper');
    await expect(count).toHaveText('2 unlinked players');
    const dubeCard = page.locator('[data-trooper]').filter({ hasText: 'Dube.R' });
    await expect(dubeCard.getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');

    // Halvorsen.K is linked to HalvoOld: the leader is asked, and Cancel changes nothing.
    const halvo = page.getByTestId('unmatched-76561198000009003');
    await halvo.getByTestId('unmatched-trooper').selectOption({ label: 'SPC Halvorsen.K' });
    await halvo.getByTestId('unmatched-link').click();
    const question = page.getByTestId('replace-dialog');
    await expect(question).toContainText(
        'SPC Halvorsen.K is linked to HalvoOld (76561198000009099) on Hell Let Loose. Replace it with Halvorsen (76561198000009003)?',
    );
    await question.getByTestId('replace-cancel').click();
    await expect(question).toHaveCount(0);
    await expect(halvo).toBeVisible();
    await expect(count).toHaveText('2 unlinked players');

    // Asked again, Replace links the new ID and pre-marks them.
    await halvo.getByTestId('unmatched-link').click();
    await page.getByTestId('replace-dialog').getByTestId('replace-confirm').click();
    await expect(halvo).toHaveCount(0);
    await expect(count).toHaveText('1 unlinked player');
    const halvoCard = page.locator('[data-trooper]').filter({ hasText: 'Halvorsen.K' });
    await expect(halvoCard.getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');

    const rando = page.getByTestId('unmatched-76561198000009002');
    await rando.getByTestId('unmatched-dismiss').click();
    await rando.getByTestId('confirm-dismiss').click();

    await expect(drawer).toHaveCount(0);
    await expect(page.getByTestId('unmatched-banner')).toHaveCount(0);
    await expect(count).toHaveCount(0);

    // Every resolution is durable: nothing is listed after a reload, and the marks came from the server.
    await page.reload();
    await expect(page.getByTestId('unmatched-drawer')).toHaveCount(0);
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Dube.R' }).getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Halvorsen.K' }).getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');
});
