import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrbatView from '../OrbatView.vue';
import { handleSlash } from '@/composables/useSlashShortcut';
import { CURRENT, roster, trooper, WEEKS } from './fixture';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

// Every mounted table holds the `/` key while it lives, so each test's tables are unmounted after it.
const mounted = [];
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.vm.$.isUnmounted || wrapper.unmount()));

function factory(props = {}) {
    const { units, troopers } = roster();
    const wrapper = mount(OrbatView, {
        props: { units, troopers, weeks: WEEKS, currentWeek: CURRENT, query: '', filter: 'all', ...props },
        attachTo: document.body,
    });
    mounted.push(wrapper);

    return wrapper;
}

describe('Order of battle (roster-s2)', () => {
    it("shows a section's nickname on its row in a platoon roster, and none across a battalion", () => {
        const { units } = roster();
        const named = units.map((unit) => (unit.id === 2 ? { ...unit, label: 'Jaegers' } : unit));

        expect(factory({ units: named }).find('[data-testid="label-2"]').text()).toBe('· Jaegers');

        const wide = [{ ...named[0], type: 'battalion' }, ...named.slice(1)];
        expect(factory({ units: wide }).find('[data-testid="label-2"]').exists()).toBe(false);
    });

    it('keeps the column headers in view, with the trooper header above the others', () => {
        const wrapper = factory();

        expect(wrapper.find('thead').element.parentElement.parentElement.dataset.stickyHead).toBeDefined();
        expect(wrapper.find('thead th').classes()).toContain('z-[4]');
    });

    it('groups troopers under section rows with the SL pinned first', () => {
        const wrapper = factory();
        const rows = wrapper.findAll('tbody tr').map((tr) => tr.attributes('data-testid'));

        expect(rows).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-13', 'trooper-12']);
        expect(wrapper.find('[data-testid="trooper-10"]').text()).toContain('SL');
    });

    // #443: the tag is the billet's short name, an acting lead's "Acting PL" and the like.
    it("prints each trooper's billet tag as sent, and none for an untagged trooper", () => {
        const wrapper = factory({ troopers: [
            trooper(10, 2, { name: 'Abernathy.L', rank: 'SGT', role: 'Acting SL', billet: 'Rifleman' }),
            trooper(11, 2, { name: 'Ferreira.D', rank: 'CPL', role: 'ASL', billet: 'Assistant Section Leader' }),
            trooper(14, 2, { name: 'Moss.K', billet: 'Rifleman' }),
            trooper(15, 1, { name: 'Hale.S', rank: 'SFC', role: 'PSG', billet: 'Platoon Sergeant 1/A/1-7' }),
        ] });
        const tag = (id) => wrapper.find(`[data-testid="trooper-${id}"] td:nth-child(2) .bg-foreground`);

        expect(tag(10).text()).toBe('Acting SL');
        expect(tag(11).text()).toBe('ASL');
        expect(tag(14).exists()).toBe(false);
        expect(tag(15).text()).toBe('PSG');
    });

    // #444: the default billet order lists a unit's command team in chain-of-command order.
    it('lists the platoon HQ as PL then PSG, and the section as SL then ASL, whatever their ranks', () => {
        const wrapper = factory({ troopers: [
            trooper(15, 1, { name: 'Hale.S', rank: 'SFC', role: 'PSG' }),
            trooper(16, 1, { name: 'Brennan.J', rank: '2LT', role: 'PL' }),
            trooper(11, 2, { name: 'Ferreira.D', rank: 'SSG', role: 'ASL' }),
            trooper(10, 2, { name: 'Abernathy.L', rank: 'SGT', role: 'SL' }),
        ] });
        const rows = wrapper.findAll('tbody tr').map((tr) => tr.attributes('data-testid'));

        expect(rows).toEqual(['trooper-16', 'trooper-15', 'section-2', 'trooper-10', 'trooper-11', 'section-3']);
    });

    it('draws an 8-week strip with the current week outlined', () => {
        const strip = factory().find('[data-testid="trooper-11"]').findAll('.strip-wk');

        expect(strip).toHaveLength(8);
        expect(strip[7].classes()).toContain('is-now');
    });

    it("labels the strip with the first and last week's start dates, apart, not two ranges run together", () => {
        const ends = factory().find('[data-testid="strip-ends"]');

        expect(ends.findAll('span').map((s) => s.text())).toEqual(['15AUG', '03OCT']);
        expect(ends.classes()).toEqual(expect.arrayContaining(['justify-between', 'gap-2']));
    });

    it('shows the small plain guidon on a platoon header, as decoration beside its name', () => {
        const { units, troopers } = roster();
        const flag = { format: 'quartered', primary: '#1f2a44', secondary: '#ffffff', topLeft: '7', bottomRight: 'A', topAlign: 'left', bottomAlign: 'left', large: '2' };
        const company = { id: 9, type: 'company', name: 'Alpha Company', callsign: 'A/1-7', parentId: null, leader: null, kpis: units[0].kpis };
        const wrapper = factory({ units: [company, { ...units[0], headerGuidon: flag }, ...units.slice(1)], troopers });
        const img = wrapper.find('[data-testid="group-1"] [data-testid="guidon"]');

        expect(img.exists()).toBe(true);
        expect(img.attributes('alt')).toBe('');
        expect(img.attributes('height')).toBe('12');
        // Plain at 12px: no text drawn.
        expect(decodeURIComponent(img.attributes('src'))).not.toContain('data-text');
        expect(wrapper.find('[data-testid="section-2"] [data-testid="guidon"]').exists()).toBe(false);
    });

    it('flags at-risk from the server row, with the miss count, and LOA separately (4.9)', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-testid="trooper-12"] [data-testid="flag-at-risk"]').text()).toContain('3 missed');
        expect(wrapper.find('[data-testid="trooper-13"]').text()).toContain('LOA');
        expect(wrapper.find('[data-testid="trooper-13"] [data-testid="flag-at-risk"]').exists()).toBe(false);
    });

    it('collapses and expands a section', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="toggle-2"]').trigger('click');
        expect(wrapper.find('[data-testid="trooper-10"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="toggle-2"]').attributes('aria-expanded')).toBe('false');

        await wrapper.findAll('button').find((b) => b.text().includes('Expand all')).trigger('click');
        expect(wrapper.find('[data-testid="trooper-10"]').exists()).toBe(true);
    });

    it('highlights search hits and opens every section with a match, hiding the rest', async () => {
        const wrapper = factory();
        await wrapper.find('[data-testid="toggle-3"]').trigger('click');

        await wrapper.setProps({ query: 'reye' });

        expect(wrapper.find('[data-testid="section-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="trooper-12"] mark').text()).toBe('Reye');
        expect(wrapper.find('#orbat-showing').text()).toContain('1 of 4 troopers match in 1 section');
    });

    it('filters to at-risk troopers', async () => {
        const wrapper = factory({ filter: 'risk' });

        expect(wrapper.findAll('[data-member]').map((tr) => tr.attributes('data-testid'))).toEqual(['trooper-12']);
    });

    it('offers to clear a filter that matches nobody', async () => {
        const wrapper = factory({ query: 'nobody' });

        expect(wrapper.text()).toContain('No troopers match "nobody"');
        await wrapper.findAll('button').find((b) => b.text() === 'Clear filter').trigger('click');
        expect(wrapper.emitted('update:query').at(-1)).toEqual(['']);
    });

    it('opens a trooper by click or keyboard, and moves between rows with the arrows', async () => {
        const wrapper = factory();
        const row = wrapper.find('[data-testid="trooper-10"]');

        await row.trigger('click');
        await row.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('open')).toEqual([[10], [10]]);

        row.element.focus();
        await row.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(wrapper.find('[data-testid="trooper-11"]').element);
        wrapper.unmount();
    });

    it('focuses the search on /, but not while a dialog is open', () => {
        // The shell owns the `/` listener (useSlashShortcut); the table only claims the key.
        const shell = (event) => handleSlash(event, () => {});
        document.addEventListener('keydown', shell);
        const wrapper = factory();
        const search = wrapper.find('[data-testid="roster-search"]').element;

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        document.body.appendChild(dialog);
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
        expect(document.activeElement).not.toBe(search);

        dialog.remove();
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
        expect(document.activeElement).toBe(search);
        wrapper.unmount();
        document.removeEventListener('keydown', shell);
    });

    it('shows a skeleton while another unit loads', () => {
        const wrapper = factory({ loading: true });

        expect(wrapper.find('table').attributes('aria-busy')).toBe('true');
        expect(wrapper.find('[data-member]').exists()).toBe(false);
    });

    it("keeps / for its own filter while mounted, instead of the shell's trooper search", () => {
        const openSearch = vi.fn();
        const shell = (event) => handleSlash(event, openSearch);
        document.addEventListener('keydown', shell);
        const wrapper = factory();

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true }));
        expect(document.activeElement).toBe(wrapper.find('[data-testid="roster-search"]').element);
        expect(openSearch).not.toHaveBeenCalled();

        wrapper.unmount();
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true }));
        expect(openSearch).toHaveBeenCalledOnce();
        document.removeEventListener('keydown', shell);
    });
});

describe('sortable columns', () => {
    const memberOrder = (wrapper) =>
        wrapper.findAll('tbody tr').map((tr) => tr.attributes('data-testid')).filter(Boolean);
    const ariaSort = (wrapper, column) => wrapper.find(`[data-testid="th-${column}"]`).attributes('aria-sort');
    const DEFAULT = ['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-13', 'trooper-12'];

    function withDates() {
        const { units, troopers } = roster();
        const dates = {
            10: { joined: '2021-02-01', tig: '2026-05-01' },
            11: { joined: '2025-09-01', tig: '2025-09-01' },
            12: { joined: '2024-01-10', tig: '2024-11-01' },
            13: { joined: '2023-07-01', tig: null },
        };

        return { units, troopers: troopers.map((t) => ({ ...t, ...dates[t.id] })) };
    }

    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-10-03T12:00:00Z') });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        localStorage.clear();
    });

    it('keeps the billet order until a header is clicked, with no column marked sorted', () => {
        const wrapper = factory();

        expect(memberOrder(wrapper)).toEqual(DEFAULT);
        for (const column of ['trooper', 'tis', 'tig', 'rc', 'sp']) expect(ariaSort(wrapper, column)).toBeUndefined();
        expect(wrapper.find('[data-testid="sort-reset"]').exists()).toBe(false);
    });

    it('puts every sort control in a button inside its column header', () => {
        const wrapper = factory();

        for (const key of ['rank', 'name', 'tis', 'tig', 'rc', 'sp']) {
            const control = wrapper.find(`[data-testid="sort-${key}"]`);
            expect(control.element.tagName).toBe('BUTTON');
            expect(control.attributes('type')).toBe('button');
            expect(control.element.closest('th')).not.toBeNull();
        }
    });

    it('sorts by name inside each section, reverses on a second click, and returns to the billet order on a third', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="sort-name"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-12', 'trooper-13']);
        expect(ariaSort(wrapper, 'trooper')).toBe('ascending');

        await wrapper.find('[data-testid="sort-name"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-11', 'trooper-10', 'section-3', 'trooper-13', 'trooper-12']);
        expect(ariaSort(wrapper, 'trooper')).toBe('descending');

        await wrapper.find('[data-testid="sort-name"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(DEFAULT);
        expect(ariaSort(wrapper, 'trooper')).toBeUndefined();
    });

    it('sorts by rank, most senior first, then junior first', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="sort-rank"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-13', 'trooper-12']);
        expect(ariaSort(wrapper, 'trooper')).toBe('descending');

        await wrapper.find('[data-testid="sort-rank"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-11', 'trooper-10', 'section-3', 'trooper-12', 'trooper-13']);
        expect(ariaSort(wrapper, 'trooper')).toBe('ascending');
    });

    it('sorts by RC 30d lowest first, keeping the billet order between equals', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="sort-rc"]').trigger('click');

        // 1st Section are both at 100%, so the SL stays first; in 2nd Section the at-risk 25% leads.
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-12', 'trooper-13']);
        expect(ariaSort(wrapper, 'rc')).toBe('ascending');
        expect(ariaSort(wrapper, 'trooper')).toBeUndefined();
    });

    it('sorts by SP 30d too', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="sort-sp"]').trigger('click');
        await wrapper.find('[data-testid="sort-sp"]').trigger('click');

        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-13', 'trooper-12']);
        expect(ariaSort(wrapper, 'sp')).toBe('descending');
    });

    it('shows time in service and time in grade, and sorts by each, longest first', async () => {
        const wrapper = factory(withDates());

        expect(wrapper.find('[data-testid="trooper-10"] [data-testid="tis"]').text()).toBe('5y 8m');
        expect(wrapper.find('[data-testid="trooper-10"] [data-testid="tig"]').text()).toBe('5m');
        expect(wrapper.find('[data-testid="trooper-11"] [data-testid="tig"]').text()).toBe('1y 1m');
        expect(wrapper.find('[data-testid="trooper-13"] [data-testid="tig"]').text()).toBe('—');

        await wrapper.find('[data-testid="sort-tis"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-13', 'trooper-12']);
        expect(ariaSort(wrapper, 'tis')).toBe('descending');

        await wrapper.find('[data-testid="sort-tig"]').trigger('click');
        // An unknown TIG sorts last.
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-11', 'trooper-10', 'section-3', 'trooper-12', 'trooper-13']);
        expect(ariaSort(wrapper, 'tig')).toBe('descending');
        expect(ariaSort(wrapper, 'tis')).toBeUndefined();

        await wrapper.find('[data-testid="sort-tig"]').trigger('click');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-12', 'trooper-13']);
        expect(ariaSort(wrapper, 'tig')).toBe('ascending');
    });

    it('stops pinning the SL on top while a column sort is on', async () => {
        const wrapper = factory();
        expect(wrapper.find('[data-testid="trooper-10"]').classes()).toContain('is-pin');

        await wrapper.find('[data-testid="sort-name"]').trigger('click');

        expect(wrapper.find('[data-testid="trooper-10"]').classes()).not.toContain('is-pin');
        expect(wrapper.find('[data-testid="trooper-10"]').text()).toContain('SL');
    });

    it('remembers the choice in this browser, and the reset clears it', async () => {
        const first = factory();
        await first.find('[data-testid="sort-name"]').trigger('click');
        await first.find('[data-testid="sort-name"]').trigger('click');
        first.unmount();

        expect(JSON.parse(localStorage.getItem('roster.orbat.sort'))).toEqual({ key: 'name', dir: 'desc' });

        const second = factory();
        expect(ariaSort(second, 'trooper')).toBe('descending');
        expect(memberOrder(second)).toEqual(['section-2', 'trooper-11', 'trooper-10', 'section-3', 'trooper-13', 'trooper-12']);

        await second.find('[data-testid="sort-reset"]').trigger('click');
        expect(memberOrder(second)).toEqual(DEFAULT);
        expect(localStorage.getItem('roster.orbat.sort')).toBeNull();
    });

    it('still sorts when the browser refuses storage', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('denied');
        });
        const wrapper = factory();

        await wrapper.find('[data-testid="sort-name"]').trigger('click');

        expect(ariaSort(wrapper, 'trooper')).toBe('ascending');
        expect(memberOrder(wrapper)).toEqual(['section-2', 'trooper-10', 'trooper-11', 'section-3', 'trooper-12', 'trooper-13']);
    });
});
