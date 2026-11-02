import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import DocsSearch from '../DocsSearch.vue';
import DocsSearchBox from '../DocsSearchBox.vue';
import { useDocsSearch, useDocsSearchShortcut } from '@/composables/useDocsSearch';
import { handleSlash } from '@/composables/useSlashShortcut';

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit },
}));

let mounted = [];

const INDEX = [
    { title: 'Add a guest to practice', summary: 'Mark a trooper from another section.', url: '/docs/weekly-rollcall/guests', section: 'Weekly rollcall', headings: [], text: '' },
    { title: 'What others can do', summary: 'Who holds which actions.', url: '/docs/start-here/what-others-can-do', section: 'Start here', headings: [], text: '' },
];

const { searchOpen } = useDocsSearch();
const inBody = (selector) => document.body.querySelector(selector);

async function openWith(index) {
    const wrapper = mount(DocsSearch, { props: { index }, attachTo: document.body });
    mounted.push(wrapper);
    searchOpen.value = true;
    await flushPromises();

    return wrapper;
}

async function type(text) {
    const input = inBody('[data-testid="docs-search-input"]');
    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

describe('docs search', () => {
    afterEach(() => {
        searchOpen.value = false;
        mounted.forEach((wrapper) => wrapper.unmount());
        mounted = [];
        document.body.innerHTML = '';
        visit.mockClear();
    });

    it('lists the guides matching what is typed, with their section, and opens one on select', async () => {
        await openWith(INDEX);
        await type('guest');

        const results = inBody('[data-testid="docs-search-results"]');
        expect(results.textContent).toContain('Add a guest to practice');
        expect(results.textContent).toContain('Weekly rollcall');

        results.querySelector('[role="option"]').click();
        await flushPromises();
        expect(visit).toHaveBeenCalledWith('/docs/weekly-rollcall/guests');
        expect(searchOpen.value).toBe(false);
    });

    it('highlights the words that matched in a result, typos included', async () => {
        await openWith(INDEX);
        await type('gust practice');

        const marks = [...inBody('[data-testid="docs-search-results"]').querySelectorAll('mark')].map((m) => m.textContent);
        expect(marks).toEqual(['guest', 'practice']);
    });

    it('says nothing was found, pointing to What others can do when the reader has it', async () => {
        await openWith(INDEX);
        await type('zzzz');

        const empty = inBody('[data-testid="docs-search-empty"]');
        expect(empty.textContent).toContain('No guide found. Try fewer words, or see');
        expect(empty.querySelector('a').getAttribute('href')).toBe('/docs/start-here/what-others-can-do');
    });

    it('names What others can do without a link when the reader has no such guide', async () => {
        await openWith([INDEX[0]]);
        await type('zzzz');

        expect(inBody('[data-testid="docs-search-empty"] a')).toBeNull();
    });

    it('opens from the search box, with / while a docs page is mounted, and with Ctrl K or Cmd K', async () => {
        const Page = defineComponent({ setup: () => (useDocsSearchShortcut(), () => h(DocsSearchBox)) });
        const page = mount(Page, { attachTo: document.body });
        mounted.push(page);
        // The shell's `/` handler, falling back to the trooper search when no page claims the key.
        const trooperSearch = vi.fn();
        const shell = (event) => handleSlash(event, trooperSearch);
        document.addEventListener('keydown', shell);

        await page.get('[data-testid="docs-search-trigger"]').trigger('click');
        expect(searchOpen.value).toBe(true);

        searchOpen.value = false;
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true }));
        expect(searchOpen.value).toBe(true);
        expect(trooperSearch).not.toHaveBeenCalled();

        // Typing a `/` into a field is never the shortcut.
        searchOpen.value = false;
        const field = document.createElement('input');
        document.body.appendChild(field);
        field.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true }));
        expect(searchOpen.value).toBe(false);
        field.remove();

        page.unmount();
        mounted = mounted.filter((m) => m !== page);
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true }));
        expect(trooperSearch).toHaveBeenCalledTimes(1);
        document.removeEventListener('keydown', shell);
        const again = mount(Page, { attachTo: document.body });
        mounted.push(again);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
        expect(searchOpen.value).toBe(true);

        searchOpen.value = false;
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'K', metaKey: true }));
        expect(searchOpen.value).toBe(true);
    });
});
