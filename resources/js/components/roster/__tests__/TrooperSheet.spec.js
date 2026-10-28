import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TrooperSheet from '../TrooperSheet.vue';
import { CURRENT, trooper, WEEKS } from './fixture';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { forum: { baseUrl: 'https://7cav.us' } } }),
}));

const LINKS = { milpac: 'https://7cav.us/rosters/profile?uniqueid=', rollcall: '/rollcall/', trooper: '/troopers/' };

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
            sectionLeader: { id: 10, rank: 'SGT', name: 'Vasquez.R', displayName: 'SGT Vasquez.R' },
            milpacUrl: 'https://7cav.us/rosters/profile?uniqueid=4012',
            ...over,
        },
        weeks: WEEKS,
        currentWeek: CURRENT,
    };
}

const LOA_SYNC = { source: 'Forum thread', lastSyncedAt: '2026-10-08T09:14:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T09:14:00+00:00' };

const factory = (trooperId = 12) =>
    mount(TrooperSheet, { props: { trooperId, links: LINKS, syncLabel: 'milpac, synced 09:14Z', loaSync: LOA_SYNC }, attachTo: document.body });

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Trooper sheet', () => {
    it('names the leave source and last sync under the KPIs, since on LOA and at-risk move with it (4.3, AD-20)', async () => {
        respond(200, detail());
        factory();
        await flushPromises();

        expect(document.body.querySelector('[data-testid="loa-sync"]').textContent).toContain('Leave from Forum thread, synced 08Oct26 09:14Z');
    });

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

    // Decided 2026-10-27: after a transfer the new leadership sees the weeks from before the move and the move itself.
    it('lists the trooper\'s transfers and names the old section on the weeks spent there', async () => {
        const weeks = WEEKS.map((w, i) => {
            if (i === 19) return { rollcall: 'will_attend', status: 'present', source: 'manual', held: true, sectionId: 3, section: '2/1/A/1-7' };
            if (i === 17) return { rollcall: 'will_attend', status: 'present', source: 'manual', held: true, sectionId: 9, section: '1/2/A/1-7' };

            return null;
        });
        respond(200, detail({ weeks, transfers: [{ at: '2026-09-30T18:00:00Z', from: '1/2/A/1-7', to: '2/1/A/1-7' }] }));
        factory();
        await flushPromises();

        const sheet = document.body.querySelector('[data-testid="trooper-sheet"]');
        expect(sheet.querySelector('[data-testid="transfers"]').textContent).toContain('Moved from 1/2/A/1-7 to 2/1/A/1-7, 30Sep26');
        const recent = sheet.querySelectorAll('[data-testid="sheet-recent"] tbody tr');
        expect(recent[0].querySelector('[data-testid="sheet-week-section"]')).toBeNull();
        expect(recent[2].querySelector('[data-testid="sheet-week-section"]').textContent).toBe('At 1/2/A/1-7');
        expect(recent[2].textContent).toContain('Present');
    });

    it('shows no transfers block for a trooper who never moved', async () => {
        respond(200, detail({ transfers: [] }));
        factory();
        await flushPromises();

        expect(document.body.querySelector('[data-testid="transfers"]')).toBeNull();
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

    it('opens the in-app milpac page, beside the link out to 7cav.us', async () => {
        respond(200, detail());
        factory();
        await flushPromises();

        expect(document.body.querySelector('[data-testid="sheet-open-trooper"]').getAttribute('href')).toBe('/troopers/12');
    });

    it('says when a trooper is outside scope rather than showing a blank sheet', async () => {
        respond(403, {});
        factory();
        await flushPromises();

        expect(document.body.textContent).toContain('This trooper is outside your scope');
    });

    it('explains a trooper that is not available (gone, or outside scope: both answer 404), without a pointless retry', async () => {
        respond(404, {});
        factory();
        await flushPromises();

        expect(document.body.textContent).toContain('This trooper is not available');
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
        expect(document.body.textContent).toContain('Could not load the trooper');

        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Try again')).click();
        await flushPromises();
        expect(document.body.textContent).toContain('PVT Reyes.M');
    });

    // #443: the unit line names the billet the trooper holds there, on every echelon.
    it("says the unit's lead leads it, and names their billet", async () => {
        respond(200, detail({ id: 12, role: 'SL', sectionLeader: { id: 12, rank: 'PVT', name: 'Reyes.M', displayName: 'PVT Reyes.M', source: 'billet' } }));
        factory();
        await flushPromises();

        const line = document.body.querySelector('[data-testid="sheet-unit"]').textContent.replace(/\s+/g, ' ').trim();
        expect(line).toBe('2/1/A/1-7, leads this section as SL');
    });

    it("names an XO's billet and who leads the company", async () => {
        respond(200, detail({ role: 'XO', section: { id: 7, type: 'company', name: 'Alpha Company', callsign: 'A/1-7' }, sectionLeader: { id: 30, rank: 'CPT', name: 'Cole.D', displayName: 'CPT Cole.D', source: 'billet' } }));
        factory();
        await flushPromises();

        const line = document.body.querySelector('[data-testid="sheet-unit"]').textContent.replace(/\s+/g, ' ').trim();
        expect(line).toBe('A/1-7, XO, led by CPT Cole.D');
    });

    it('shows an acting lead as acting, not as the billet holder', async () => {
        respond(200, detail({ role: 'Acting PL', section: { id: 5, type: 'platoon', name: '1st Platoon', callsign: '1/A/1-7' }, sectionLeader: { id: 12, rank: 'PVT', name: 'Reyes.M', displayName: 'PVT Reyes.M', source: 'acting' } }));
        factory();
        await flushPromises();

        const line = document.body.querySelector('[data-testid="sheet-unit"]').textContent.replace(/\s+/g, ' ').trim();
        expect(line).toBe('1/A/1-7, leads this platoon as Acting PL');
    });

    it("marks the unit's lead as acting for a trooper they lead", async () => {
        respond(200, detail({ section: { id: 5, type: 'platoon', name: '1st Platoon', callsign: '1/A/1-7' }, sectionLeader: { id: 40, rank: 'SGT', name: 'Oduya.N', displayName: 'SGT Oduya.N', source: 'acting' } }));
        factory();
        await flushPromises();

        const line = document.body.querySelector('[data-testid="sheet-unit"]').textContent.replace(/\s+/g, ' ').trim();
        expect(line).toBe('1/A/1-7, led by SGT Oduya.N (acting)');
    });

    it('stays closed with no trooper selected', () => {
        globalThis.fetch = vi.fn();
        factory(null);

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(document.body.querySelector('[data-testid="trooper-sheet"]')).toBeNull();
    });

    describe('makeup PM', () => {
        it('opens the forum\'s own conversation form, pre-filled, in a new tab, for a trooper who missed practices', async () => {
            respond(200, detail({ missedWeeks: ['2026-09-26', '2026-10-03'] }));
            factory();
            await flushPromises();

            const link = document.body.querySelector('[data-testid="sheet-makeup-pm"]');
            expect(link.getAttribute('href')).toBe('https://7cav.us/conversations/add?to=Reyes.M&title=Section%20practice%20makeup%3A%20weeks%20of%2026Sep26%2C%2003Oct26');
            expect(link.getAttribute('target')).toBe('_blank');
            expect(link.getAttribute('rel')).toBe('noopener noreferrer');
            expect(link.textContent).toContain('Send makeup PM');
        });

        it('is not offered when the trooper missed nothing in the window', async () => {
            respond(200, detail({ missedWeeks: [] }));
            factory();
            await flushPromises();

            expect(document.body.querySelector('[data-testid="sheet-makeup-pm"]')).toBeNull();
        });

        it('is not offered for a trooper with no forum username', async () => {
            respond(200, detail({ name: null, missedWeeks: ['2026-10-03'] }));
            factory();
            await flushPromises();

            expect(document.body.querySelector('[data-testid="sheet-makeup-pm"]')).toBeNull();
            expect(document.body.querySelector('[data-testid="sheet-milpac"]')).not.toBeNull();
        });
    });
});
