import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref, withDirectives } from 'vue';
import { mount } from '@vue/test-utils';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { stickyHeadMode, vStickyHead } from '../stickyHead';

// happy-dom does no layout, so widths are set by hand on the wrapper.
function setWidths(el, { scroll, client }) {
    Object.defineProperty(el, 'scrollWidth', { configurable: true, get: () => scroll });
    Object.defineProperty(el, 'clientWidth', { configurable: true, get: () => client });
}

// A stand-in ResizeObserver that a test can fire by hand.
function stubResizeObserver() {
    const observers = [];
    class FakeResizeObserver {
        constructor(callback) {
            this.callback = callback;
            this.targets = [];
            this.disconnect = vi.fn();
            observers.push(this);
        }
        observe(target) {
            this.targets.push(target);
        }
    }
    vi.stubGlobal('ResizeObserver', FakeResizeObserver);
    return observers;
}

function mountWrapper(value) {
    const mode = ref(value);
    const Host = defineComponent({
        setup: () => () =>
            withDirectives(h('div', { 'data-testid': 'wrap' }, [h('table', [h('thead', [h('tr', [h('th', 'Name')])])])]), [[vStickyHead, mode.value]]),
    });
    return { wrapper: mount(Host), mode };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('stickyHeadMode', () => {
    it('is page when the table fits its wrapper', () => {
        const el = document.createElement('div');
        setWidths(el, { scroll: 800, client: 800 });

        expect(stickyHeadMode(el)).toBe('page');
    });

    it('is box when the table is wider than its wrapper', () => {
        const el = document.createElement('div');
        setWidths(el, { scroll: 880, client: 720 });

        expect(stickyHeadMode(el)).toBe('box');
    });

    it('ignores a sub-pixel rounding difference', () => {
        const el = document.createElement('div');
        setWidths(el, { scroll: 801, client: 800 });

        expect(stickyHeadMode(el)).toBe('page');
    });
});

describe('v-sticky-head', () => {
    it('marks the wrapper on mount and follows its width as it changes', () => {
        const observers = stubResizeObserver();
        const { wrapper } = mountWrapper(undefined);
        const el = wrapper.find('[data-testid="wrap"]').element;

        // No layout under test: both widths read 0, so the table fits.
        expect(el.dataset.stickyHead).toBe('page');
        expect(observers).toHaveLength(1);
        expect(observers[0].targets).toEqual([el, el.querySelector('table')]);

        setWidths(el, { scroll: 900, client: 600 });
        observers[0].callback();
        expect(el.dataset.stickyHead).toBe('box');

        setWidths(el, { scroll: 600, client: 600 });
        observers[0].callback();
        expect(el.dataset.stickyHead).toBe('page');
    });

    it('stops watching when the table goes away', () => {
        const observers = stubResizeObserver();
        const { wrapper } = mountWrapper(undefined);

        wrapper.unmount();

        expect(observers[0].disconnect).toHaveBeenCalled();
    });

    it("keeps a table in box mode with 'box', without watching its width", () => {
        const observers = stubResizeObserver();
        const { wrapper } = mountWrapper('box');

        expect(wrapper.find('[data-testid="wrap"]').element.dataset.stickyHead).toBe('box');
        expect(observers).toHaveLength(0);
    });

    it('turns off with false, and back on when the value changes', async () => {
        stubResizeObserver();
        const { wrapper, mode } = mountWrapper(false);
        const el = wrapper.find('[data-testid="wrap"]').element;

        expect(el.dataset.stickyHead).toBeUndefined();

        mode.value = 'box';
        await nextTick();
        expect(el.dataset.stickyHead).toBe('box');

        mode.value = false;
        await nextTick();
        expect(el.dataset.stickyHead).toBeUndefined();
    });

    it('still sets a mode where ResizeObserver is missing', () => {
        vi.stubGlobal('ResizeObserver', undefined);
        const { wrapper } = mountWrapper(undefined);

        expect(wrapper.find('[data-testid="wrap"]').element.dataset.stickyHead).toBe('page');
    });
});

describe('ui Table sticky-header', () => {
    const slots = {
        default: () => h(TableHeader, () => h(TableRow, () => h(TableHead, () => 'Name'))),
    };

    it('leaves the header alone by default', () => {
        const wrapper = mount(Table, { slots });

        expect(wrapper.find('[data-slot="table-container"]').attributes('data-sticky-head')).toBeUndefined();
    });

    it('makes the header sticky with sticky-header', () => {
        stubResizeObserver();
        const wrapper = mount(Table, { props: { stickyHeader: true }, slots });

        expect(wrapper.find('[data-slot="table-container"]').attributes('data-sticky-head')).toBe('page');
    });

    it("keeps it in its own box with sticky-header='box'", () => {
        const wrapper = mount(Table, { props: { stickyHeader: 'box' }, slots: { default: () => h(TableBody) } });

        expect(wrapper.find('[data-slot="table-container"]').attributes('data-sticky-head')).toBe('box');
    });
});
