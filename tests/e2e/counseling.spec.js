import { expect, test } from '@playwright/test';

// #381: filing and seeing Developmental Counseling Statements and Letters of Reprimand on the trooper page. Against
// CounselingSeeder: Lindqvist.P leads 1/F/1-7 and Sato.K its 1st Section; their rifleman Novak.E already has a DCS for a
// missed section practice and an LOR for other conduct from 20 days ago. Google Drive is not configured here, so records
// stay in the portal only.

async function openNovak(page, leader) {
    await page.goto(`/__e2e/login/${leader}?to=/roster`);
    await page.locator('tr[data-member]').filter({ hasText: 'Novak.E' }).click();
    await page.getByTestId('trooper-sheet').getByTestId('sheet-open-trooper').click();
    await expect(page).toHaveURL(/\/troopers\/\d+$/);

    return page.getByTestId('counseling-panel');
}

test('a platoon leader files an LOR with its PDF, sees it listed and flagged, and downloads it', async ({ page }) => {
    const panel = await openNovak(page, 'Lindqvist.P');

    await expect(panel.getByTestId('counseling-record')).toHaveCount(2);
    await expect(panel.locator('[data-testid="counseling-record"][data-type="dcs"]')).toContainText('Missed two section practices without notice.');
    // The seeded LOR is 20 days old: no favorable action for 10 more days.
    await expect(panel.getByTestId('counseling-warning-no-favorable-action')).toContainText('No favorable action until');

    await panel.getByTestId('counseling-file-open').click();
    await panel.getByTestId('counseling-type').selectOption('lor');
    await panel.getByTestId('counseling-kind').selectOption('conduct');
    await expect(panel.getByTestId('counseling-hint-lor-removal')).toHaveText('2nd LOR within 90 days: removal as SL or ASL is authorized.');
    await panel.getByTestId('counseling-reason').fill('Left practice early without telling the section leader.');
    await panel.getByTestId('counseling-document').setInputFiles({
        name: 'novak-lor.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n'),
    });
    await panel.getByTestId('counseling-submit').click();

    await expect(page.getByText('Letter of Reprimand filed')).toBeVisible();
    await expect(panel.getByTestId('counseling-record')).toHaveCount(3);
    const filed = panel.getByTestId('counseling-record').filter({ hasText: 'Left practice early' });
    await expect(filed.getByTestId('counseling-type-badge')).toContainText('LOR');
    await expect(filed.getByTestId('counseling-issuer')).toHaveText('Filed by Lindqvist.P');
    await expect(filed.getByTestId('counseling-drive')).toHaveText('Portal only');
    await expect(filed.getByTestId('counseling-flag-lor-removal')).toBeVisible();
    await expect(filed.getByTestId('counseling-kind-text')).toHaveText('Other conduct');

    const href = await filed.getByTestId('counseling-download').getAttribute('href');
    const download = await page.request.get(href);
    expect(download.status()).toBe(200);
    expect(download.headers()['content-type']).toBe('application/pdf');
    expect(download.headers()['content-disposition']).toMatch(/^attachment; filename="LOR \d{4}-\d{2}-\d{2} Novak\.E\.pdf"$/);
    expect((await download.body()).toString()).toContain('%PDF-1.4');
});

test('a section leader sees the trooper\'s DCS and LOR, and may file only a DCS', async ({ page }) => {
    const panel = await openNovak(page, 'Sato.K');

    await expect(panel.locator('[data-testid="counseling-record"][data-type="dcs"]').first()).toContainText('Filed by Sato.K');
    // The other test may file another LOR at the same time, so find the seeded one by its reason.
    await expect(panel.getByTestId('counseling-record').filter({ hasText: 'Disrespect toward an NCO during practice.' })).toHaveAttribute('data-type', 'lor');

    await panel.getByTestId('counseling-file-open').click();
    await expect(panel.getByTestId('counseling-type').locator('option')).toHaveText(['Developmental Counseling Statement (DCS)']);
    await panel.getByTestId('counseling-kind').selectOption('missed_practice');
    await expect(panel.getByTestId('counseling-hint-missed-lor')).toHaveText('Second miss within 30 days of the last: an LOR is called for.');
});
