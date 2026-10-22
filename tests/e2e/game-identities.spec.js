import { expect, test } from '@playwright/test';

// Story 7.2 happy path. Vasquez.R commands one seeded section of eight troopers
// (ReportRollupSeeder), none of them linked to a game yet.

test('a section leader links their troopers, hits a collision, then edits and removes an identity', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/home');
    await page.getByTestId('nav-Game identities').click();

    await expect(page).toHaveURL(/\/game-identities$/);
    await expect(page.getByRole('heading', { name: 'Game identities' })).toBeVisible();
    await expect(page.getByTestId('coverage-linked')).toHaveText('0');
    await expect(page.getByTestId('coverage-slots')).toHaveText('24');
    await expect(page.getByTestId('view-needs')).toHaveAttribute('aria-pressed', 'true');

    const rows = page.locator('[data-testid^="row-"]');
    await expect(rows).toHaveCount(8);
    const firstName = (await page.getByTestId(/^row-/).first().locator('p').first().textContent()).trim();

    // Link all three games for the first trooper: the queue then drops them.
    await rows.first().getByRole('button', { name: 'Add identity' }).click();
    await expect(page.getByTestId('identity-game')).toHaveValue('hll');
    await page.getByTestId('identity-name').fill('QueueOne');
    await page.getByTestId('identity-id').fill('76561198000000001');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('1');
    await expect(page.getByTestId('identity-game')).toHaveValue('arma');
    await page.getByTestId('identity-name').fill('QueueOne_Arma');
    await page.getByTestId('identity-id').fill('arma-0001');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('2');
    await expect(page.getByTestId('identity-game')).toHaveValue('wardogs');
    await page.getByTestId('identity-name').fill('QueueOne_Wardogs');
    await page.getByTestId('identity-id').fill('76561198000000002');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('3');
    await expect(rows).toHaveCount(7);

    // The same Steam ID on another trooper is a readable error on the field, not a 500.
    await rows.first().getByRole('button', { name: 'Add identity' }).click();
    await page.getByTestId('identity-name').fill('Copycat');
    await page.getByTestId('identity-id').fill('76561198000000001');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('error-platform_id')).toContainText(`Already linked to ${firstName} on Hell Let Loose`);
    await expect(page.getByTestId('coverage-linked')).toHaveText('3');
    await page.getByTestId('identity-cancel').click();

    // Everyone view: rename in place (same id), then remove.
    await page.getByTestId('view-everyone').click();
    await expect(rows).toHaveCount(8);
    await page.locator('[data-testid^="open-"]').filter({ hasText: 'Edit identities' }).click();
    await page.getByTestId('edit-hll').click();
    await page.getByTestId('identity-name').fill('QueueOne_Renamed');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('identity-hll')).toContainText('QueueOne_Renamed');
    await expect(page.getByTestId('coverage-linked')).toHaveText('3');

    await page.getByTestId('remove-arma').click();
    await page.getByTestId('confirm-remove-arma').click();
    await expect(page.getByTestId('identity-arma')).toHaveCount(0);
    await expect(page.getByTestId('coverage-linked')).toHaveText('2');
});
