import { expect, test } from '@playwright/test';

// Story 7.7 happy path, against CaptureReviewSeeder: Hartmann.L leads a section whose practice this
// week was captured with three players no trooper is linked to ("Dubes", "RandoGuy88" and "Halvorsen";
// Halvorsen.K already holds another HLL ID, so linking them asks to replace or cancel, #176).

test('a section leader links unmatched players to troopers, replacing an old ID only when asked, and dismisses another, with undo', async ({ page }) => {
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
        'SPC Halvorsen.K is linked to HalvoOld (76561198000009099) on Hell Let Loose (WW2). Replace it with Halvorsen (76561198000009003)?',
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
    const dismissRando = async () => {
        await rando.getByTestId('unmatched-dismiss').click();
        await rando.getByTestId('confirm-dismiss').click();
        await expect(rando).toHaveCount(0);
        await expect(count).toHaveCount(0);
    };
    await dismissRando();
    await expect(page.getByTestId('unmatched-banner')).toHaveCount(0);

    // A dismissal can be undone (#176): from the toast...
    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(rando).toBeVisible();
    await expect(count).toHaveText('1 unlinked player');

    // ...or from the drawer's Dismissed list, which stays once nobody is left to link.
    await dismissRando();
    const dismissed = drawer.getByTestId('dismissed-list');
    await expect(dismissed.locator('summary')).toHaveText('Dismissed (1)');
    await dismissed.locator('summary').click();
    await dismissed.getByTestId('dismissed-76561198000009002').getByTestId('undo-dismiss').click();
    await expect(rando).toBeVisible();
    await expect(dismissed).toHaveCount(0);

    await dismissRando();

    // Every resolution is durable: after a reload nobody is waiting, the dismissal is listed, and the marks came from the server.
    await page.reload();
    await expect(page.getByTestId('unmatched-banner')).toHaveCount(0);
    await expect(page.getByTestId('unmatched-76561198000009002')).toHaveCount(0);
    await expect(page.getByTestId('dismissed-76561198000009002')).toHaveCount(1);
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Dube.R' }).getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-trooper]').filter({ hasText: 'Halvorsen.K' }).getByRole('button', { name: /^Present/ })).toHaveAttribute('aria-pressed', 'true');
});
