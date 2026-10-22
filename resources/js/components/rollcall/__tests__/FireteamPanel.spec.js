import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import FireteamPanel from '../FireteamPanel.vue';

// The rollcall's fireteam controls (the owner's order, 2026-10-16): a section's leaders show FTLs and choose where they
// go, mark them and fill their fireteams; a company's command enforces a policy, and alone lets the SL and ASL lead a
// fireteam; everyone else sees it read-only.

enableAutoUnmount(afterEach);
const post = vi.fn();
vi.mock('@/lib/postJson', () => ({ postJson: (...args) => post(...args) }));

const t = (id, name, rankShort, role = null, fireteamLeaderId = null, leadsFireteam = role === 'ftl') => ({
    id, cavId: 1000 + id, name, rankShort, displayName: `${rankShort} ${name}`, role, leadsFireteam, fireteamLeaderId,
});
const TROOPERS = [t(1, 'Sl.A', 'SGT', 'sl'), t(2, 'Asl.B', 'CPL', 'asl'), t(3, 'Ftl.C', 'CPL', 'ftl'), t(4, 'Member.D', 'PFC', null, 3), t(5, 'Loner.E', 'PVT')];
const options = (over = {}) => ({
    show: true,
    order: 'by_fireteam',
    slAslLead: false,
    alphabet: 'nato',
    section: { show: true, order: 'by_fireteam' },
    company: { id: 7, designation: 'A/1-7', show: null, order: null, slAslLead: false },
    can: { edit: true, editPolicy: false },
    ...over,
});
const saved = (over = {}) => ({ ok: true, status: 200, data: { fireteams: options(over), troopers: [] } });

const factory = (props = {}) => mount(FireteamPanel, { props: { sectionId: 12, troopers: TROOPERS, fireteams: options(), ...props } });
const find = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);
const pressed = (wrapper, id) => find(wrapper, id).findAll('button').map((button) => [button.text(), button.attributes('aria-pressed')]);
const optionTexts = (row) => row.findAll('option').map((option) => option.text());

describe('FireteamPanel', () => {
    beforeEach(() => post.mockReset().mockResolvedValue(saved()));

    it('says how the post is ordered, with FTLs hidden by default and no fireteam list', () => {
        const wrapper = factory({ fireteams: options({ show: false, order: 'leaders_first' }) });

        expect(find(wrapper, 'fireteams-summary').text()).toBe('The post lists the SL, then the ASL, then everyone by rank.');
        expect(pressed(wrapper, 'fireteams-show')).toEqual([['Hidden', 'true'], ['Shown', 'false']]);
        expect(find(wrapper, 'fireteams-order').exists()).toBe(false);
        expect(find(wrapper, 'fireteams-list').exists()).toBe(false);
    });

    it('turns FTLs on and switches the order, each saved at once and handed to the page', async () => {
        const wrapper = factory({ fireteams: options({ show: false, order: 'leaders_first' }) });

        await find(wrapper, 'fireteams-show').findAll('button')[1].trigger('click');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams/options', { show: true });
        expect(wrapper.emitted('updated')[0][0].fireteams.show).toBe(true);

        await wrapper.setProps({ fireteams: options({ order: 'leaders_first' }) });
        await find(wrapper, 'fireteams-order').findAll('button')[1].trigger('click');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams/options', { order: 'by_fireteam' });
    });

    it('lists the SL and ASL first with no choice, names each fireteam, and offers everyone else FTL or a fireteam', async () => {
        const wrapper = factory();
        await find(wrapper, 'fireteams-toggle').trigger('click');

        expect(find(wrapper, 'fireteam-1').text()).toContain('SL');
        expect(find(wrapper, 'fireteam-1').text()).toContain('Listed first');
        expect(find(wrapper, 'fireteam-2').find('select').exists()).toBe(false);
        expect(find(wrapper, 'fireteam-3').find('[data-testid="fireteam-name"]').text()).toBe('Alpha');
        expect(find(wrapper, 'fireteam-3').find('select').element.value).toBe('ftl');
        expect(find(wrapper, 'fireteam-4').find('select').element.value).toBe('member:3');
        expect(optionTexts(find(wrapper, 'fireteam-5'))).toEqual(['No fireteam role', 'Fireteam leader', 'In Alpha (CPL Ftl.C)']);
        // An FTL is not offered their own fireteam.
        expect(find(wrapper, 'fireteam-3').findAll('option')).toHaveLength(2);
    });

    it('names fireteams in the WW2 alphabet for B/ACD', async () => {
        const wrapper = factory({ fireteams: options({ alphabet: 'ww2' }) });
        await find(wrapper, 'fireteams-toggle').trigger('click');

        expect(find(wrapper, 'fireteam-3').find('[data-testid="fireteam-name"]').text()).toBe('Able');
    });

    it('lets the SL and ASL lead a fireteam where the company allows it, never join one', async () => {
        const troopers = [t(1, 'Sl.A', 'SGT', 'sl', null, true), ...TROOPERS.slice(1)];
        const wrapper = factory({ troopers, fireteams: options({ slAslLead: true }) });
        await find(wrapper, 'fireteams-toggle').trigger('click');

        expect(find(wrapper, 'fireteam-1').find('select').element.value).toBe('ftl');
        expect(find(wrapper, 'fireteam-1').find('[data-testid="fireteam-name"]').text()).toBe('Alpha');
        expect(optionTexts(find(wrapper, 'fireteam-2'))).toEqual(['No fireteam role', 'Fireteam leader']);
        expect(optionTexts(find(wrapper, 'fireteam-5'))).toEqual(['No fireteam role', 'Fireteam leader', 'In Alpha (SGT Sl.A)', 'In Bravo (CPL Ftl.C)']);

        await find(wrapper, 'fireteam-2').find('select').setValue('ftl');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams', { trooper_id: 2, role: 'ftl', leader_id: null });
    });

    it('offers fireteams only with the by-fireteam order, keeping a trooper\'s current one', async () => {
        const wrapper = factory({ fireteams: options({ order: 'leaders_first' }) });
        await find(wrapper, 'fireteams-toggle').trigger('click');

        expect(find(wrapper, 'fireteam-5').findAll('option')).toHaveLength(2);
        expect(find(wrapper, 'fireteam-4').find('select').element.value).toBe('member:3');
    });

    it('marks an FTL, puts a trooper in a fireteam and takes one out', async () => {
        const wrapper = factory();
        await find(wrapper, 'fireteams-toggle').trigger('click');

        await find(wrapper, 'fireteam-5').find('select').setValue('ftl');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams', { trooper_id: 5, role: 'ftl', leader_id: null });

        await find(wrapper, 'fireteam-5').find('select').setValue('member:3');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams', { trooper_id: 5, role: 'member', leader_id: 3 });

        await find(wrapper, 'fireteam-4').find('select').setValue('');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams', { trooper_id: 4, role: null, leader_id: null });
        expect(wrapper.emitted('updated')).toHaveLength(3);
    });

    it('shows a refusal in words and puts the select back to what the server holds', async () => {
        post.mockResolvedValue({ ok: false, status: 422, data: { message: 'Trooper 3 is not a fireteam leader in this section. Mark them as FTL first.' } });
        const wrapper = factory();
        await find(wrapper, 'fireteams-toggle').trigger('click');

        await find(wrapper, 'fireteam-5').find('select').setValue('member:3');
        await flushPromises();

        expect(find(wrapper, 'fireteams-error').text()).toContain('not a fireteam leader');
        expect(find(wrapper, 'fireteam-5').find('select').element.value).toBe('');
        expect(wrapper.emitted('updated')).toBeUndefined();
    });

    it('locks a choice the company enforces and says so, shown as well as hidden', () => {
        const wrapper = factory({ fireteams: options({ show: true, order: 'leaders_first', company: { id: 7, designation: 'A/1-7', show: null, order: 'leaders_first', slAslLead: false } }) });

        expect(find(wrapper, 'fireteams-order-enforced').text()).toBe('Set by company');
        expect(find(wrapper, 'fireteams-order').findAll('button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
        expect(find(wrapper, 'fireteams-show').findAll('button').every((button) => button.attributes('disabled') === undefined)).toBe(true);

        for (const show of [false, true]) {
            const forced = factory({ fireteams: options({ show, company: { id: 7, designation: 'A/1-7', show, order: null, slAslLead: false } }) });
            expect(find(forced, 'fireteams-show-enforced').text()).toBe('Set by company');
            expect(find(forced, 'fireteams-show').findAll('button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
        }
    });

    it('shows a leader without command the controls read-only, and a view-as preview too', async () => {
        const reader = factory({ fireteams: options({ can: { edit: false, editPolicy: false } }) });
        await find(reader, 'fireteams-toggle').trigger('click');

        expect(find(reader, 'fireteams-read-only').text()).toBe('Only the leaders who command this section can change its fireteams');
        expect(find(reader, 'fireteam-3').find('select').attributes('disabled')).toBeDefined();
        expect(find(reader, 'fireteams-show').findAll('button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
        expect(find(reader, 'fireteams-policy').exists()).toBe(false);

        const preview = factory({ editable: false, disabledReason: 'Read-only while viewing as 1/1/A/1-7', fireteams: options({ can: { edit: true, editPolicy: true } }) });
        expect(find(preview, 'fireteams-read-only').text()).toBe('Read-only while viewing as 1/1/A/1-7');
        expect(find(preview, 'fireteams-policy').exists()).toBe(false);
        await find(preview, 'fireteams-show').findAll('button')[0].trigger('click');
        expect(post).not.toHaveBeenCalled();
    });

    it('lets the company\'s command set the policy, sending the whole policy each time', async () => {
        const wrapper = factory({ fireteams: options({ can: { edit: true, editPolicy: true }, company: { id: 7, designation: 'A/1-7', show: null, order: 'leaders_first', slAslLead: false } }) });

        expect(find(wrapper, 'fireteams-policy').text()).toContain('Company policy, every section in A/1-7');
        expect(find(wrapper, 'fireteams-policy-order').element.value).toBe('leaders_first');
        expect(find(wrapper, 'fireteams-policy-sl-asl').element.value).toBe('no');

        await find(wrapper, 'fireteams-policy-show').setValue('hidden');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams/policy', { show: false, order: 'leaders_first', sl_asl_lead: false });

        await find(wrapper, 'fireteams-policy-show').setValue('shown');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams/policy', { show: true, order: 'leaders_first', sl_asl_lead: false });

        await find(wrapper, 'fireteams-policy-order').setValue('');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams/policy', { show: null, order: null, sl_asl_lead: false });

        await find(wrapper, 'fireteams-policy-sl-asl').setValue('yes');
        await flushPromises();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12/fireteams/policy', { show: null, order: 'leaders_first', sl_asl_lead: true });
    });
});
