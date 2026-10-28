import { expect, test } from '@playwright/test';

// #428: a game with several ID kinds (Hell Let Loose (WW2) starts with Steam, Epic and Xbox since #431) asks for the
// kind first in a section leader's identity form, and an Admin can drop a kind and add it back. Nothing is linked, but
// the game's kinds change for everyone while it runs, so playwright.config.js runs it after the other specs, not
// beside them.

test('an Admin gives a game several ID kinds and the identity form asks for the kind first', async ({ browser }) => {
    const admin = await (await browser.newContext()).newPage();
    await admin.goto('/__e2e/login/Pike.S?to=/settings/games');
    await expect(admin.getByTestId('kinds-hll')).toHaveText('Steam ID, Epic ID, Xbox ID');

    const leader = await (await browser.newContext()).newPage();
    await leader.goto('/__e2e/login/Vasquez.R?to=/game-identities');
    await leader.locator('[data-testid^="open-"]').first().click();
    // Whichever trooper opens first, and whatever they are already linked on, pick the game under test.
    await leader.getByTestId('identity-game').selectOption('hll');
    await expect(leader.getByTestId('identity-kind')).toHaveValue('Steam ID');
    await expect(leader.getByTestId('identity-id')).toHaveAttribute('placeholder', 'Paste the Steam ID');

    await leader.getByTestId('identity-kind').selectOption('Epic ID');
    await expect(leader.locator('label[for="identity-id"]')).toHaveText('Epic ID');
    await expect(leader.getByTestId('identity-id')).toHaveAttribute('placeholder', 'Paste the Epic ID');

    await leader.getByTestId('identity-game').selectOption('arma');
    await expect(leader.getByTestId('identity-kind')).toHaveCount(0);

    // Drop Xbox and add it back: no identity uses it, so the removal is allowed.
    await admin.getByTestId('edit-hll').click();
    await admin.getByTestId('remove-kind-2').click();
    await admin.getByTestId('submit-game').click();
    await expect(admin.getByTestId('kinds-hll')).toHaveText('Steam ID, Epic ID');

    await admin.getByTestId('edit-hll').click();
    await admin.getByTestId('add-kind').click();
    await admin.getByTestId('game-id_kind-2').fill('Xbox ID');
    await admin.getByTestId('submit-game').click();
    await expect(admin.getByTestId('kinds-hll')).toHaveText('Steam ID, Epic ID, Xbox ID');
});
