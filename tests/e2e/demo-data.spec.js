import { test, expect } from '@playwright/test';

// #115 browser check, guest side: the Demo data screen is never served to a signed-out visitor.
// The signed-in screen is not driven here because the e2e server runs without DEMO_TOOLS, so it
// would 404; DemoDataControllerTest covers it with the flag on and off.

test('a guest opening Settings → Demo data lands on the login screen', async ({ page }) => {
    await page.goto('/settings/demo-data');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});
