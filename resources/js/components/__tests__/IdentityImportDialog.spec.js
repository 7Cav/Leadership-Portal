import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import IdentityImportDialog from '../IdentityImportDialog.vue';

const routerPost = vi.hoisted(() => vi.fn());
const postJson = vi.hoisted(() => vi.fn());

vi.mock('@/lib/postJson', () => ({ postJson }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), router: { post: routerPost } }));

const PLATFORMS = [
    { value: 'hll', label: 'Hell Let Loose', idKinds: ['Steam ID', 'Epic ID'], retired: false },
    { value: 'old', label: 'Old Game', idKinds: ['Player ID'], retired: true },
];

const PREVIEW = {
    lines: [
        { line: 2, trooper: 'SGT Novak.E', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000001', status: 'add', reason: null, current: null, steam: 'not_checked' },
        { line: 3, trooper: 'SGT Ortega.M', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000002', status: 'change', reason: null, current: '76561198000000009', steam: 'checked' },
        { line: 4, trooper: 'Nobody.X', game: 'HLL', idKind: null, platformId: '1', status: 'refused', reason: 'No trooper is named Nobody.X.', current: null, steam: null },
        { line: 5, trooper: 'SGT Dube.R', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000003', status: 'skip', reason: 'Already has 1.', current: '1', steam: null },
    ],
    counts: { add: 1, change: 1, skip: 1, unchanged: 0, refused: 1 },
};

let wrapper;
const body = (selector) => document.body.querySelector(selector);

function mountDialog() {
    wrapper = mount(IdentityImportDialog, { props: { open: true, platforms: PLATFORMS }, attachTo: document.body });
}

async function type(value) {
    const textarea = body('[data-testid="identity-import-text"]');
    textarea.value = value;
    textarea.dispatchEvent(new Event('input'));
    await flushPromises();
}

afterEach(() => {
    wrapper?.unmount();
    postJson.mockReset();
    routerPost.mockReset();
});

describe('IdentityImportDialog', () => {
    it('previews each line with its result and reason, then imports the lines to save', async () => {
        postJson.mockResolvedValue({ ok: true, data: PREVIEW });
        mountDialog();
        await flushPromises();

        await type('Novak.E, HLL, 76561198000000001');
        body('[data-testid="identity-import-preview-button"]').click();
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/game-identities/import/preview', { text: 'Novak.E, HLL, 76561198000000001', replace: false });
        expect(body('[data-testid="identity-import-counts"]').textContent).toContain('1 to add, 1 to change, 0 unchanged, 1 skipped, 1 refused.');
        expect(body('[data-testid="identity-import-not-checked"]').textContent).toContain('1 Steam ID was not checked');
        expect(body('[data-testid="identity-import-line-2"]').textContent).toContain('Will add');
        expect(body('[data-testid="identity-import-line-3"]').textContent).toContain('was 76561198000000009');
        expect(body('[data-testid="identity-import-line-4"]').textContent).toContain('No trooper is named Nobody.X.');
        expect(body('[data-testid="identity-import-line-5"]').textContent).toContain('Skipped');

        const save = body('[data-testid="identity-import-save"]');
        expect(save.textContent.trim()).toBe('Import 2 identities');
        save.click();
        expect(routerPost).toHaveBeenCalledWith('/game-identities/import', { text: 'Novak.E, HLL, 76561198000000001', replace: false, expected: [2, 3] }, expect.any(Object));
    });

    it('labels a kept line and a not serving line, and imports them with the rest', async () => {
        postJson.mockResolvedValue({
            ok: true,
            data: {
                lines: [
                    { line: 1, trooper: 'Atkins.DS', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000011', status: 'keep', reason: 'No trooper named Atkins.DS yet.', current: null, steam: null },
                    { line: 2, trooper: 'PVT Atkins.A', game: 'Hell Let Loose', idKind: 'Steam ID', platformId: '76561198000000012', status: 'add', reason: null, current: null, steam: null, notServing: true },
                ],
                counts: { add: 1, change: 0, keep: 1, skip: 0, unchanged: 0, refused: 0 },
            },
        });
        mountDialog();
        await flushPromises();
        await type('Atkins.DS, HLL, 76561198000000011');
        body('[data-testid="identity-import-preview-button"]').click();
        await flushPromises();

        expect(body('[data-testid="identity-import-line-1"]').textContent).toContain('Kept on file');
        expect(body('[data-testid="identity-import-line-1"]').textContent).toContain('No trooper named Atkins.DS yet.');
        expect(body('[data-testid="identity-import-not-serving-2"]').textContent).toContain('Not serving, kept for if they rejoin.');
        expect(body('[data-testid="identity-import-counts"]').textContent).toContain('1 to keep on file');
        body('[data-testid="identity-import-save"]').click();
        expect(routerPost).toHaveBeenCalledWith('/game-identities/import', expect.objectContaining({ expected: [1, 2] }), expect.any(Object));
    });

    it('previews again with the replace tick, and drops the preview when the text changes', async () => {
        postJson.mockResolvedValue({ ok: true, data: PREVIEW });
        mountDialog();
        await flushPromises();
        await type('Dube.R, HLL, 76561198000000003');
        body('[data-testid="identity-import-preview-button"]').click();
        await flushPromises();

        body('[data-testid="identity-import-replace"]').click();
        await flushPromises();
        expect(postJson).toHaveBeenLastCalledWith('/game-identities/import/preview', { text: 'Dube.R, HLL, 76561198000000003', replace: true });

        await type('something else');
        expect(body('[data-testid="identity-import-preview"]')).toBeNull();
        expect(body('[data-testid="identity-import-preview-button"]')).not.toBeNull();
    });

    it('shows the server refusal of the text', async () => {
        postJson.mockResolvedValue({ ok: false, data: { message: 'x', errors: { text: ['This is not a text file. Save it as .txt or .csv and try again.'] } } });
        mountDialog();
        await flushPromises();
        await type('junk');
        body('[data-testid="identity-import-preview-button"]').click();
        await flushPromises();

        expect(body('[data-testid="identity-import-error"]').textContent).toBe('This is not a text file. Save it as .txt or .csv and try again.');
    });

    it('reads a chosen .txt file into the text and refuses another type or a file over 256 KB', async () => {
        mountDialog();
        await flushPromises();
        const input = body('[data-testid="identity-import-file"]');
        const choose = async (file) => {
            Object.defineProperty(input, 'files', { value: [file], configurable: true });
            input.dispatchEvent(new Event('change'));
            await flushPromises();
        };

        await choose(new File(['Novak.E, HLL, 1'], 'ids.txt', { type: 'text/plain' }));
        expect(body('[data-testid="identity-import-text"]').value).toBe('Novak.E, HLL, 1');

        await choose(new File(['x'], 'ids.xlsx'));
        expect(body('[data-testid="identity-import-error"]').textContent).toBe('Choose a .txt or .csv file.');

        await choose(new File(['x'.repeat(256 * 1024 + 1)], 'big.csv'));
        expect(body('[data-testid="identity-import-error"]').textContent).toContain('larger than 256 KB');
    });

    it('downloads an example with a line for each active game', async () => {
        let blob;
        URL.createObjectURL = vi.fn((b) => ((blob = b), 'blob:x'));
        URL.revokeObjectURL = vi.fn();
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        mountDialog();
        await flushPromises();

        body('[data-testid="identity-import-example"]').click();
        const text = await blob.text();

        expect(click).toHaveBeenCalled();
        expect(text).toContain('trooper, game, ID kind, ID');
        expect(text).toContain('Novak.E, Hell Let Loose, , 76561198012345678');
        expect(text).not.toContain('Old Game');
        click.mockRestore();
    });
});
