import { test, expect } from '@playwright/test';

// #115 browser check. Signing in needs the real 7cav.us OAuth round-trip, so this stays on the
// guest side like the shell checks: the Demo data screen is never served to a signed-out visitor.

test('a guest opening Settings → Demo data lands on the login screen', async ({ page }) => {
    await page.goto('/settings/demo-data');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});
