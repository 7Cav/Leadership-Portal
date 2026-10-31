import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import CounselingImport from '../CounselingImport.vue';

const routerPost = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));
vi.mock('@/lib/counselingImport', async (importOriginal) => ({
    ...(await importOriginal()),
    readAllImportFields: vi.fn(async (files) => Object.fromEntries(files.map((file) => [file.name, { type: 'dcs' }]))),
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { post: (...args) => routerPost(...args) },
}));

const COLUMNS = ['drive_url', 'file', 'type', 'trooper', 'kind', 'issuer', 'counseled_on'];
const LIMITS = { rows: 200, files: 200, fileBytes: 10 * 1024 * 1024 };
const row = (line, extra = {}) => ({
    line,
    source: 'file',
    label: `doc-${line}.pdf`,
    outcome: 'import',
    reason: null,
    notes: [],
    type: 'dcs',
    trooper: 'PFC Okafor.D',
    issuer: 'Duval.E',
    counseledOn: '2025-03-12',
    candidates: [],
    recordId: null,
    ...extra,
});
const CHECK = {
    batch: '9b2f5c1e-3d4a-4b6c-8e7f-0a1b2c3d4e5f',
    csvName: 'bravo-2025.csv',
    checkedAt: '2025-10-30T22:31:00Z',
    dryRun: true,
    counts: { all: 4, import: 1, review: 2, skipped: 1 },
    rows: [
        row(2),
        row(7, { source: 'drive', label: 'https://drive.google.com/file/d/1AbCdEf9Kp2Lm/view', outcome: 'review', reason: '2 troopers are named Novak.J: pick one', trooper: 'Novak.J', candidates: [{ id: 11, name: 'PFC Novak.J' }, { id: 12, name: 'SGT Novak.J' }] }),
        row(11, { outcome: 'review', reason: 'No form fields could be read (a scanned or flattened PDF): fill them in after the import.', notes: ['No issuer named: attributed to you.'] }),
        row(14, { source: 'drive', label: 'https://drive.google.com/drive/folders/7Ab', outcome: 'skipped', reason: 'folder links are not supported', type: null, trooper: null, issuer: null, counseledOn: null }),
    ],
};

const mountPage = (props = {}) => mount(CounselingImport, { props: { columns: COLUMNS, limits: LIMITS, check: null, ...props } });

beforeEach(() => routerPost.mockReset());

describe('Settings/CounselingImport', () => {
    it('lists the template columns and links the template download', () => {
        const page = mountPage();

        expect(page.get('[data-testid="template-columns"]').text()).toBe(COLUMNS.join(''));
        expect(page.get('[data-testid="download-template"]').attributes('href')).toBe('/settings/counseling-import/template');
        expect(page.find('[data-testid="check-result"]').exists()).toBe(false);
    });

    it('asks for the CSV before checking', async () => {
        const page = mountPage();

        await page.get('form').trigger('submit');

        expect(page.get('[data-testid="csv-error"]').text()).toBe('Choose the CSV file.');
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('reads the PDFs and sends what it read with the files', async () => {
        const page = mountPage();
        const csv = new File(['drive_url,file\n'], 'x.csv', { type: 'text/csv' });
        const pdf = new File(['%PDF-1.4'], 'a.pdf', { type: 'application/pdf' });
        Object.defineProperty(page.get('[data-testid="csv-input"]').element, 'files', { value: [csv] });
        Object.defineProperty(page.get('[data-testid="pdf-input"]').element, 'files', { value: [pdf] });
        await page.get('[data-testid="csv-input"]').trigger('change');
        await page.get('[data-testid="pdf-input"]').trigger('change');

        await page.get('form').trigger('submit');
        await flushPromises();

        const [url, data, options] = routerPost.mock.calls[0];
        expect(url).toBe('/settings/counseling-import/check');
        expect(data.csv.name).toBe('x.csv');
        expect(data.files.map((file) => file.name)).toEqual(['a.pdf']);
        expect(JSON.parse(data.fields)).toEqual({ 'a.pdf': { type: 'dcs' } });
        expect(options.forceFormData).toBe(true);
    });

    it('shows each row with its result, and the reason a row was skipped', () => {
        const page = mountPage({ check: CHECK });

        expect(page.get('[data-testid="row-2"]').text()).toContain('doc-2.pdf');
        expect(page.get('[data-testid="result-2"]').text()).toBe('Import');
        expect(page.get('[data-testid="row-7"]').text()).toContain('drive …/9Kp2Lm');
        expect(page.get('[data-testid="result-11"]').text()).toBe('Needs review');
        expect(page.get('[data-testid="row-11"]').text()).toContain('No issuer named: attributed to you.');
        expect(page.get('[data-testid="result-14"]').text()).toBe('Skipped: folder links are not supported');
        expect(page.get('[data-testid="check-result"]').text()).toContain('bravo-2025.csv · 4 rows');
    });

    it('counts a row waiting for a trooper only once one is picked, and sends the pick', async () => {
        const page = mountPage({ check: CHECK });
        expect(page.get('[data-testid="import-records"]').text()).toBe('Import 2 records');

        await page.get('select[data-testid="pick-7"]').setValue('12');
        await nextTick();
        expect(page.get('[data-testid="import-records"]').text()).toBe('Import 3 records');

        await page.get('[data-testid="import-records"]').trigger('click');
        const [url, data] = routerPost.mock.calls[0];
        expect(url).toBe(`/settings/counseling-import/${CHECK.batch}`);
        expect(data).toEqual({ picks: { 7: 12 } });
    });
});
