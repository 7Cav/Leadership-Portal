import { afterEach, describe, it, expect, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import OrgStructure from '../OrgStructure.vue';
import { chooseSearch } from '@/testing/searchSelect';
import { moveOutcome, movePreview, ordinalOf } from '@/lib/unitDesignation';

// The Move dialog (2026-11-02): a move rebuilds the designation from the new parent, and a clash there is settled before
// the save. The edit form is reactive here so the dialog follows the picked parent, as it does in the page.
const sent = [];

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: vi.fn(), post: vi.fn() },
    useForm: (initial) =>
        reactive({
            ...initial,
            processing: false,
            errors: {},
            post: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            patch(url) {
                sent.push({ url, parent_id: this.parent_id, clash: this.clash, clash_ordinal: this.clash_ordinal });
            },
        }),
}));

const unit = (id, parent_id, type, name, callsign, path, extra = {}) => ({ id, parent_id, type, name, callsign, path, trooper_count: 0, can_renumber: true, ...extra });

const units = (overrides = {}) => [
    unit(1, null, 'regiment', '7th Cavalry Regiment', '7CAV', '1/'),
    unit(2, 1, 'battalion', '1st Battalion', '1-7', '1/2/'),
    unit(3, 2, 'company', 'Alpha Company', 'A/1-7', '1/2/3/'),
    unit(4, 2, 'company', 'Golf Company', 'G/1-7', '1/2/4/'),
    unit(5, 3, 'platoon', '1st Platoon', '1/A/1-7', '1/2/3/5/'),
    unit(6, 5, 'section', '1st Section', '1/1/A/1-7', '1/2/3/5/6/'),
    unit(7, 4, 'platoon', '1st Platoon', '1/G/1-7', '1/2/4/7/', overrides[7] ?? {}),
    unit(8, 4, 'platoon', '2nd Platoon', '2/G/1-7', '1/2/4/8/'),
    unit(9, 2, 'company', 'Hotel Company', 'H/1-7', '1/2/9/'),
];

const inBody = (testid) => document.body.querySelector(`[data-testid="${testid}"]`);

async function openMove(list, parentId) {
    const wrapper = mount(OrgStructure, { props: { units: list, isAdmin: true }, attachTo: document.body });
    await wrapper.find('[data-testid="unit-row-5"]').trigger('click');
    await chooseSearch(wrapper, 'edit-parent_id', parentId);
    await wrapper.find('[data-testid="edit-unit-form"]').trigger('submit');
    await flushPromises();
    return wrapper;
}

const squash = (text) => text.replace(/\s+/g, ' ').trim();
const rowText = (id) => ({
    before: squash(inBody(`move-row-${id}`).querySelector('[data-testid="move-row-before"]').textContent),
    after: squash(inBody(`move-row-${id}`).querySelector('[data-testid="move-row-after"]').textContent),
});

async function pick(testid) {
    const input = inBody(testid);
    input.checked = true;
    input.dispatchEvent(new Event('change'));
    await flushPromises();
}

async function choose(value) {
    const select = inBody('clash-ordinal');
    select.value = value;
    select.dispatchEvent(new Event('change'));
    await flushPromises();
}

afterEach(() => {
    sent.length = 0;
    document.body.innerHTML = '';
});

describe('unitDesignation move helpers', () => {
    it('reads the ordinal back from a callsign, and nothing from one without a designation shape', () => {
        expect(ordinalOf('platoon', '1/A/1-7')).toBe('1');
        expect(ordinalOf('section', '2/1/A/1-7')).toBe('2');
        expect(ordinalOf('company', 'G/1-7')).toBe('G');
        expect(ordinalOf('battalion', 'ACD')).toBeNull();
    });

    it('rebuilds from the new parent and finds the unit already holding the designation', () => {
        const list = units();
        const plan = movePreview(list[4], list[3], list);

        expect(plan.from).toBe('1/A/1-7');
        expect(plan.to).toBe('1/G/1-7');
        expect(plan.below).toBe(1);
        expect(plan.clash.id).toBe(7);
        expect(plan.swap).toBe(true);
        expect(plan.options.map((option) => option.callsign)).not.toContain('1/G/1-7');
        expect(plan.options.map((option) => option.callsign)).not.toContain('2/G/1-7');
    });
});

describe('moveOutcome', () => {
    const list = units();
    const plan = movePreview(list[4], list[3], list);

    it('lists the moved unit, and the one already there only once an option changes it', () => {
        expect(moveOutcome(plan, list[4], list[3], list).map((row) => row.id)).toEqual([5]);
        expect(moveOutcome(plan, list[4], list[3], list, 'renumber_moved', '').map((row) => row.id)).toEqual([5]);
        expect(moveOutcome(plan, list[4], list[3], list, 'swap').map((row) => row.id)).toEqual([5, 7]);
    });

    it('rebuilds the callsigns of the units under each, and waits for a pick on a renumber', () => {
        const [moved, there] = moveOutcome(plan, list[4], list[3], list, 'renumber_existing', '');
        expect(moved.below).toEqual([{ id: 6, type: 'section', name: '1st Section', from: '1/1/A/1-7', to: '1/1/G/1-7' }]);
        expect(there.after).toEqual({ name: '1st Platoon', callsign: null, parent: 'Golf Company' });
    });
});

describe('Settings/OrgStructure Move dialog', () => {
    it('saves straight away when nothing under the new parent holds the designation', async () => {
        const wrapper = await openMove(units(), 9);

        expect(inBody('move-summary').textContent).toContain('1st Platoon moves from Alpha Company to Hotel Company');
        expect(inBody('move-summary').textContent).toContain('the unit under it');
        expect(inBody('move-clash')).toBeNull();
        expect(rowText(5)).toEqual({ before: '1st Platoon, Alpha Company 1/A/1-7', after: 'becomes 1st Platoon, Hotel Company 1/H/1-7' });
        expect(inBody('move-row-below-5').textContent).toContain('Its 1 section');
        expect(inBody('move-row-below-5').textContent).toMatch(/1st Section\s*1\/1\/A\/1-7\s*→\s*1\/1\/H\/1-7/);

        inBody('move-confirm').click();
        expect(sent).toEqual([{ url: '/settings/org-structure/5', parent_id: 9, clash: null, clash_ordinal: '' }]);
        wrapper.unmount();
    });

    it('says the designation exists and waits for a choice before it moves', async () => {
        const wrapper = await openMove(units(), 4);

        expect(inBody('move-clash-message').textContent).toContain('Golf Company already has a 1st Platoon (1/G/1-7).');
        expect(inBody('clash-swap-label').textContent).toBe('Swap the two platoons');
        expect(inBody('clash-renumber-existing-label').textContent).toBe('Renumber the platoon already in Golf Company');
        expect(inBody('clash-renumber-moved-label').textContent).toBe('Give the moved platoon a different number');
        expect(inBody('move-confirm').disabled).toBe(true);
        expect(inBody('move-outcome-pending')).not.toBeNull();
        expect(inBody('move-row-7')).toBeNull();

        await pick('clash-swap');
        expect(inBody('move-confirm').disabled).toBe(false);
        expect(rowText(5)).toEqual({ before: '1st Platoon, Alpha Company 1/A/1-7', after: 'becomes 1st Platoon, Golf Company 1/G/1-7' });
        expect(rowText(7)).toEqual({ before: '1st Platoon, Golf Company 1/G/1-7', after: 'becomes 1st Platoon, Alpha Company 1/A/1-7' });
        inBody('move-confirm').click();
        expect(sent.at(-1)).toMatchObject({ parent_id: 4, clash: 'swap' });
        wrapper.unmount();
    });

    it('offers the free numbers for a renumber and sends the one picked', async () => {
        const wrapper = await openMove(units(), 4);

        await pick('clash-renumber-existing');
        expect(inBody('clash-ordinal-label').textContent.trim()).toBe('New number for the 1st Platoon already in Golf Company');
        expect(rowText(7).after).toBe('becomes 1st Platoon, Golf Company pick a number');
        const offered = [...inBody('clash-ordinal').querySelectorAll('option')].map((option) => option.value).filter(Boolean);
        expect(offered).toEqual(['3', '4', '5', '6', '7', '8', '9']);
        expect(inBody('move-confirm').disabled).toBe(true);

        await choose('3');
        expect(rowText(7)).toEqual({ before: '1st Platoon, Golf Company 1/G/1-7', after: 'becomes 3rd Platoon, Golf Company 3/G/1-7' });
        expect(rowText(5).after).toBe('becomes 1st Platoon, Golf Company 1/G/1-7');
        inBody('move-confirm').click();
        expect(sent.at(-1)).toMatchObject({ clash: 'renumber_existing', clash_ordinal: '3' });

        await pick('clash-renumber-moved');
        expect(inBody('clash-ordinal-label').textContent.trim()).toBe('New number for the moved platoon');
        await choose('4');
        expect(rowText(5).after).toBe('becomes 4th Platoon, Golf Company 4/G/1-7');
        expect(inBody('move-row-below-5').textContent).toMatch(/1\/1\/A\/1-7\s*→\s*1\/4\/G\/1-7/);
        expect(inBody('move-row-7')).toBeNull();
        inBody('move-confirm').click();
        expect(sent.at(-1)).toMatchObject({ clash: 'renumber_moved', clash_ordinal: '4' });
        wrapper.unmount();
    });

    it('hides swap and renumbering the other unit, with the reason, when it is outside write scope', async () => {
        const wrapper = await openMove(units({ 7: { can_renumber: false } }), 4);

        expect(inBody('move-clash-out-of-scope').textContent).toContain('That platoon (1/G/1-7) is outside your write scope');
        expect(inBody('clash-swap')).toBeNull();
        expect(inBody('clash-renumber-existing')).toBeNull();
        expect(inBody('clash-renumber-moved')).not.toBeNull();
        wrapper.unmount();
    });

    it('blocks a swap, saying why, when the old callsign is held under the old parent too', async () => {
        const list = [...units(), unit(10, 3, 'platoon', 'Duplicate', '1/A/1-7', '1/2/3/10/')];
        const wrapper = await openMove(list, 4);

        expect(inBody('clash-swap').disabled).toBe(true);
        expect(inBody('clash-swap-blocked').textContent).toContain('1/A/1-7 is taken under A/1-7 too');
        wrapper.unmount();
    });
});
