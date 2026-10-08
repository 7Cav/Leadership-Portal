import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TrooperSheet from '../TrooperSheet.vue';
import { CURRENT, trooper, WEEKS } from './fixture';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const LINKS = { milpac: 'https://7cav.us/rosters/profile?uniqueid=', rollcall: '/rollcall/' };

function respond(status, body) {
    globalThis.fetch = vi.fn().mockResolvedValue({ ok: status < 400, status, json: async () => body });
}

function detail(over = {}) {
    return {
        trooper: {
            ...trooper(12, 3, {
                name: 'Reyes.M',
                rank: 'PVT',
                kpis: { rc: [1, 4], sp: [1, 4], missed: 3, atRisk: true, onLoa: false },
                weeks: WEEKS.map((w, i) => (i === 19 ? { rollcall: 'will_attend', status: 'present', source: 'auto', held: true } : null)),
            }),
            mos: '11B',
            tig: '2026-02-01',
            section: { id: 3, type: 'section', name: '2nd Section', callsign: '2/1/A/1-7' },
            sectionLeader: { id: 10, rank: 'SGT', name: 'Vasquez.R' },
            milpacUrl: 'https://7cav.us/rosters/profile?uniqueid=4012',
            ...over,
        },
        weeks: WEEKS,
        currentWeek: CURRENT,
    };
}

const factory = (trooperId = 12) =>
    mount(TrooperSheet, { props: { trooperId, links: LINKS, syncLabel: 'milpac, synced 09:14Z' }, attachTo: document.body });

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Trooper sheet', () => {
    it('loads the trooper from the scope-checked endpoint and shows identity, at-risk and marks with source', async () => {
        respond(200, detail());
        factory();
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledWith('/roster/troopers/12', expect.objectContaining({ headers: expect.objectContaining({ Accept: 'application/json' }) }));
        const sheet = document.body.querySelector('[data-testid="trooper-sheet"]');
        expect(sheet.textContent).toContain('PVT Reyes.M');
        expect(sheet.textContent).toContain('led by SGT Vasquez.R');
        expect(sheet.querySelector('[data-testid="sheet-at-risk"]').textContent).toContain('Missed 3');
        const recent = sheet.querySelectorAll('[data-testid="sheet-recent"] tbody tr');
        expect(recent).toHaveLength(8);
        expect(recent[0].textContent).toContain('03Oct26');
        expect(recent[0].textContent).toContain('Auto, game server capture');
        expect(recent[1].textContent).toContain('No rollcall posted');
    });

    it('links to the section rollcall and to milpac in a new tab', async () => {
        respond(200, detail());
        factory();
        await flushPromises();

        expect(document.body.querySelector('[data-testid="sheet-open-rollcall"]').getAttribute('href')).toBe('/rollcall/3');
        const milpac = document.body.querySelector('[data-testid="sheet-milpac"]');
        expect(milpac.getAttribute('href')).toBe('https://7cav.us/rosters/profile?uniqueid=4012');
        expect(milpac.getAttribute('target')).toBe('_blank');
        expect(milpac.getAttribute('rel')).toContain('noopener');
    });

    it('says when a trooper is outside scope rather than showing a blank sheet', async () => {
        respond(403, {});
        factory();
        await flushPromises();

        expect(document.body.textContent).toContain('This trooper is outside your scope.');
    });

    it('explains a trooper no longer on the roster, without a pointless retry', async () => {
        respond(404, {});
        factory();
        await flushPromises();

        expect(document.body.textContent).toContain('This trooper is no longer on the roster.');
        expect([...document.body.querySelectorAll('button')].some((b) => b.textContent.includes('Try again'))).toBe(false);
    });

    it('hides Open rollcall until the rollcall screen exists', async () => {
        respond(200, detail());
        mount(TrooperSheet, { props: { trooperId: 12, links: { ...LINKS, rollcall: null }, syncLabel: 'milpac' }, attachTo: document.body });
        await flushPromises();

        expect(document.body.querySelector('[data-testid="sheet-open-rollcall"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="sheet-milpac"]')).not.toBeNull();
    });

    it('offers a retry when the load fails', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        globalThis.fetch = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue({ ok: true, status: 200, json: async () => detail() });
        factory();
        await flushPromises();
        expect(document.body.textContent).toContain('The trooper could not be loaded.');

        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Try again')).click();
        await flushPromises();
        expect(document.body.textContent).toContain('PVT Reyes.M');
    });

    it('stays closed with no trooper selected', () => {
        globalThis.fetch = vi.fn();
        factory(null);

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(document.body.querySelector('[data-testid="trooper-sheet"]')).toBeNull();
    });
});
