import { expect, test } from '@playwright/test';

// Staff, ELOA and New Recruit placement (decided 2026-10-24), against OrgPlacementSeeder: an Admin (Pike.S, from
// ReportRollupSeeder) opens Settings, Org structure. The DEVCOM platoon counts its Platoon Sergeant (placed on the
// platoon itself) with its section's trooper, shows its WARDOGS label and a vacant lead; ELOA lists Marlowe.E with the
// section he left; New Recruits stay hidden until the toggle shows them.

test('the org page counts HQ staff, hides New Recruits by default and shows where an ELOA trooper was', async ({ page }) => {
    await page.goto('/__e2e/login/Pike.S?to=/settings/org-structure');

    const platoon = page.locator('[data-testid^="unit-row-"]').filter({ hasText: 'DEVCOM Platoon' });
    await expect(platoon).toContainText('Headcount 2');
    await expect(platoon).toContainText('WARDOGS');
    await expect(platoon).toContainText('Lead: vacant');

    const recruits = page.locator('[data-testid^="unit-row-"]').filter({ hasText: 'New Recruits' });
    await expect(recruits).toHaveCount(0);
    await page.getByTestId('show-recruits').check();
    await expect(recruits).toContainText('1 trooper');

    await page.locator('[data-testid^="unit-row-"]').filter({ hasText: 'Extended Leave of Absence' }).click();
    const member = page.getByTestId('group-members').getByTestId('group-member').filter({ hasText: 'Marlowe.E' });
    await expect(member.getByTestId('member-was')).toHaveText('was: 1/9/D/DEVCOM');
});
