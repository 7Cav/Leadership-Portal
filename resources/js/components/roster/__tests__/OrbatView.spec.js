import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrbatView from '../OrbatView.vue';
import { handleSlash } from '@/composables/useSlashShortcut';
import { CURRENT, roster, WEEKS } from './fixture';

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

    it('draws an 8-week strip with the current week outlined', () => {
        const strip = factory().find('[data-testid="trooper-11"]').findAll('.strip-wk');

        expect(strip).toHaveLength(8);
        expect(strip[7].classes()).toContain('is-now');
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

        expect(wrapper.text()).toContain('No troopers match “nobody”');
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
