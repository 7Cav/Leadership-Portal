import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { toast } from 'vue-sonner';
import Record from '../Record.vue';

// #565: one counseling record. The page draws what the server sent and posts each step; who may do what is the
// server's (`can`), and every route checks again.

const sent = vi.fn();
// Errors the next request answers with, as the server's validation would.
let nextErrors = null;
// The page the next successful post answers with (its shared props), or undefined.
let nextPage;
const guards = [];

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));
vi.mock('@/lib/counselingPdf', () => ({ fillCounselingPdf: vi.fn(), saveFile: vi.fn(), downloadCounselingDocument: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');
    const pick = (form, keys) => JSON.stringify(Object.fromEntries(keys.map((key) => [key, form[key]])));

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: {
            on: (event, callback) => {
                guards.push(callback);

                return () => {};
            },
        },
        useForm: (data) => {
            const keys = Object.keys(data);
            let snapshot = JSON.stringify(data);
            let transform = (payload) => payload;
            const form = reactive({
                ...JSON.parse(JSON.stringify(data)),
                errors: {},
                processing: false,
                get isDirty() {
                    return pick(this, keys) !== snapshot;
                },
                defaults() {
                    snapshot = pick(this, keys);
                },
                clearErrors(field) {
                    if (field) delete this.errors[field];
                    else this.errors = {};
                },
                setError(field, message) {
                    this.errors[field] = message;
                },
                transform(callback) {
                    transform = callback;

                    return this;
                },
            });
            for (const method of ['post', 'patch', 'delete']) {
                form[method] = (url, options) => {
                    sent(method, url, transform(JSON.parse(pick(form, keys))));
                    if (nextErrors) {
                        form.errors = nextErrors;
                        options?.onError?.(nextErrors);
                        nextErrors = null;
        nextPage = undefined;
                    } else {
                        options?.onSuccess?.(nextPage);
                    }
                };
            }

            return form;
        },
    };
});

const KINDS = [
    { value: 'missed_practice', label: 'Missed section practice or roll call' },
    { value: 'conduct', label: 'Other conduct' },
];

const record = (overrides = {}) => ({
    id: 42,
    type: 'lor',
    typeLabel: 'Letter of Reprimand',
    kind: 'missed_practice',
    kindLabel: 'Missed section practice or roll call',
    state: 'draft',
    status: 'draft',
    version: 1,
    source: 'portal',
    needsReview: false,
    hasSourceDocument: false,
    issuer: 'Varga.T',
    startedAt: '2026-10-28T10:00:00Z',
    savedAt: '2026-10-31T11:58:00Z',
    date: null,
    reason: null,
    form: { infraction: 'Missed practice on 10, 17 and 24 Oct.', regulation: '2/B/1-7 Platoon SOP' },
    meeting: { windows: [], invitedAt: null, closesAt: null, confirmed: false },
    sharedAt: null,
    respondBy: null,
    locksAt: null,
    frozen: false,
    respondedAt: null,
    withdrawn: null,
    appeal: null,
    overturned: null,
    dcsPhase: null,
    ...overrides,
});

const props = (overrides = {}) => ({
    record: record(overrides.record),
    trooper: { id: 7, name: 'Okafor.D', rank: 'PFC', billet: 'Rifleman' },
    section1: { date: null, trooper: { rank: 'PFC', name: 'Okafor.D', billet: 'Rifleman' }, authority: { rank: '2LT', name: 'Varga.T', billet: 'Platoon Leader' } },
    document: null,
    missing: { invite: 'Send the meeting invite.', discussion: 'Write the notes of discussion.' },
    appealAuthority: 'CPT Halvorsen.R',
    can: { edit: true, delete: true, invite: true, share: true, withdraw: false, respond: false, decide: false, overturn: false, review: false, fileLor: true },
    pm: { allowed: true, consentUrl: '/auth/forum-pm?return=%2Fcounseling%2F42' },
    limits: { long: 3000, plan: 2000, short: 500, comment: 1000, reason: 2000, window: 60 },
    kinds: KINDS,
    ...overrides,
    ...(overrides.record ? { record: record(overrides.record) } : {}),
});

const page = (overrides = {}) => mount(Record, { props: props(overrides), attachTo: document.body });
const open = async (wrapper, key) => {
    await wrapper.find(`[data-testid="step-edit-${key}"]`).trigger('click');
    await flushPromises();
};

describe('Counseling/Record', () => {
    beforeEach(() => {
        sent.mockReset();
        toast.success.mockReset();
        nextPage = undefined;
        nextErrors = null;
        guards.length = 0;
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-31T12:00:00Z'));
    });
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    describe('the leader’s draft', () => {
        it('opens on the first step still open, with when it was last saved', () => {
            const wrapper = page();

            expect(wrapper.find('[data-testid="counseling-draft"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="step-invite"]').attributes('aria-current')).toBe('step');
            expect(wrapper.find('[data-testid="draft-saved"]').text()).toBe('Saved 2 min ago');
            expect(wrapper.find('[data-testid="counseling-state"]').text()).toBe('Draft');
        });

        it('saves the whole draft from any step', async () => {
            const wrapper = page();
            await open(wrapper, 'what');
            await wrapper.find('[data-testid="draft-regulation"]').setValue('Platoon SOP, attendance');
            await wrapper.find('[data-testid="draft-save"]').trigger('click');

            expect(sent).toHaveBeenCalledWith('patch', '/counseling/42', {
                type: 'lor',
                kind: 'missed_practice',
                counseled_on: null,
                meeting_confirmed: false,
                form: {
                    infraction: 'Missed practice on 10, 17 and 24 Oct.',
                    regulation: 'Platoon SOP, attendance',
                    discussion: '',
                    plan_authority: '',
                    plan_other: '',
                    comments_authority: '',
                    comments_other: '',
                    consequences: [],
                    removal: null,
                    special_punishments: '',
                },
            });
        });

        it('saves on Continue and moves to the next step; with nothing changed it only moves', async () => {
            const wrapper = page();
            await open(wrapper, 'meeting');
            await wrapper.find('[data-testid="draft-date"]').setValue('2026-10-29');
            await wrapper.find('[data-testid="draft-meeting-confirmed"]').setValue(true);
            await wrapper.find('[data-testid="step-meeting"] [data-testid="step-continue"]').trigger('click');
            await flushPromises();

            expect(sent).toHaveBeenCalledTimes(1);
            expect(sent.mock.calls[0][2]).toMatchObject({ counseled_on: '2026-10-29', meeting_confirmed: true });
            expect(wrapper.find('[data-testid="step-consequence"]').attributes('aria-current')).toBe('step');

            await wrapper.find('[data-testid="step-consequence"] [data-testid="step-continue"]').trigger('click');
            await flushPromises();
            expect(sent).toHaveBeenCalledTimes(1);
            expect(wrapper.find('[data-testid="step-share"]').attributes('aria-current')).toBe('step');
        });

        it('fills the live preview from unsaved values', async () => {
            const wrapper = page();
            await open(wrapper, 'meeting');
            await wrapper.find('[data-testid="draft-discussion"]').setValue('We went through the LOA process.');

            expect(wrapper.find('[data-testid="form-preview"]').text()).toContain('We went through the LOA process.');
        });

        it('asks before leaving with unsaved changes, and lets the page’s own saves through', async () => {
            const original = window.confirm;
            const confirm = (window.confirm = vi.fn(() => false));
            const wrapper = page();
            const guard = guards[0];

            expect(guard({ detail: { visit: { method: 'get' } } })).toBeUndefined();
            await open(wrapper, 'what');
            await wrapper.find('[data-testid="draft-infraction"]').setValue('Changed');
            expect(guard({ detail: { visit: { method: 'patch' } } })).toBeUndefined();
            expect(guard({ detail: { visit: { method: 'get' } } })).toBe(false);
            expect(confirm).toHaveBeenCalled();
            window.confirm = original;
        });

        it('lists what blocks share and never shares while anything is missing', async () => {
            const wrapper = page();
            await open(wrapper, 'share');

            const blocked = wrapper.find('[data-testid="share-blocked"]');
            expect(blocked.findAll('li').map((li) => li.text())).toEqual(['Send the meeting invite.', 'Write the notes of discussion.']);
            expect(wrapper.find('[data-testid="checklist-invite"]').attributes('data-done')).toBe('false');
            expect(wrapper.find('[data-testid="checklist-infraction"]').attributes('data-done')).toBe('true');
            expect(wrapper.find('[data-testid="share-step"]').text()).toContain('Before you can share 6/8');
            expect(wrapper.find('[data-testid="share-submit"]').attributes('disabled')).toBeDefined();
            expect(sent).not.toHaveBeenCalled();
        });

        it('shares from the explicit Share step once nothing is missing', async () => {
            const wrapper = page({ missing: [] });
            await open(wrapper, 'share');
            await wrapper.find('[data-testid="share-submit"]').trigger('click');

            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/share', {});
            expect(toast.success).toHaveBeenCalledWith('Shared with the trooper');
        });

        it('leaves the S1 follow-up to the shell\'s notice instead of a success toast', async () => {
            const wrapper = page({ missing: [] });
            await open(wrapper, 'share');
            nextPage = { props: { flash: { notice: 'The letter was shared with the trooper, but the forum refused its copy to S1. Send the letter to S1 yourself.' } } };
            await wrapper.find('[data-testid="share-submit"]').trigger('click');

            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/share', {});
            expect(toast.success).not.toHaveBeenCalledWith('Shared with the trooper');
        });

        it('asks for PM permission before the invite or share, linking to the forum consent page', async () => {
            const wrapper = page({ missing: [], pm: { allowed: false, consentUrl: '/auth/forum-pm?return=%2Fcounseling%2F42' } });

            expect(wrapper.find('[data-testid="pm-consent-link"]').attributes('href')).toBe('/auth/forum-pm?return=%2Fcounseling%2F42');
            expect(wrapper.find('[data-testid="invite-send"]').attributes('disabled')).toBeDefined();

            await open(wrapper, 'share');
            expect(wrapper.find('[data-testid="pm-consent"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="share-submit"]').attributes('disabled')).toBeDefined();
        });

        it('shows the server’s PM refusals: consent again, or the forum not taking the PM', async () => {
            const wrapper = page();
            for (const [i, value] of ['Thu 29 Oct 2000Z', 'Fri 30 Oct 1900Z', 'Sat 31 Oct 1700Z'].entries()) await wrapper.find(`[data-testid="invite-window-${i}"]`).setValue(value);

            nextErrors = { pm_consent: 'Allow the portal to send PMs from your forum account again.' };
            await wrapper.find('[data-testid="invite-send"]').trigger('click');
            await flushPromises();
            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/invite', { windows: ['Thu 29 Oct 2000Z', 'Fri 30 Oct 1900Z', 'Sat 31 Oct 1700Z'] });
            expect(wrapper.find('[data-testid="pm-consent"]').text()).toContain('Allow the portal to send PMs from your forum account again.');

            nextErrors = { pm: 'The forum did not send the PM.' };
            await wrapper.find('[data-testid="invite-send"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="pm-error"]').text()).toContain('The forum did not send the PM. The record is unchanged');
        });

        it('shows the invite as sent with its windows once it went out', () => {
            const wrapper = page({
                record: { meeting: { windows: ['Thu 29 Oct 2000Z', 'Fri 30 Oct 1900Z', 'Sat 31 Oct 1700Z'], invitedAt: '2026-10-28T20:04:00Z', closesAt: '2026-10-31T20:04:00Z', confirmed: false } },
                can: { ...props().can, invite: false },
                missing: { discussion: 'Write the notes of discussion.' },
            });

            expect(wrapper.find('[data-testid="counseling-invited"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="deadline-meeting"]').text()).toContain('Closes 31 Oct 2004Z');
            expect(wrapper.find('[data-testid="draft-meeting-banner"]').text()).toContain('in 8 h 4 min');
        });

        it('offers no LOR to a leader who may not file one', () => {
            const wrapper = page({ record: { type: 'dcs', typeLabel: 'Developmental Counseling Statement' }, can: { ...props().can, fileLor: false } });

            return open(wrapper, 'type').then(() => {
                expect(wrapper.find('[data-testid="draft-type-lor"]').attributes('disabled')).toBeDefined();
                expect(wrapper.find('[data-testid="draft-type-dcs"]').attributes('disabled')).toBeUndefined();
                // A DCS has no Consequence step.
                expect(wrapper.find('[data-testid="step-consequence"]').exists()).toBe(false);
            });
        });

        it('deletes the draft after asking', async () => {
            const wrapper = page();
            await wrapper.find('[data-testid="draft-delete"]').trigger('click');
            await flushPromises();
            document.querySelector('[data-testid="draft-delete-dialog-confirm"]').click();
            await flushPromises();

            expect(sent).toHaveBeenCalledWith('delete', '/counseling/42', {});
        });
    });

    describe('the trooper’s response', () => {
        const shared = {
            state: 'shared',
            status: 'shared',
            date: '2026-10-29',
            sharedAt: '2026-10-30T14:00:00Z',
            respondBy: '2026-10-31T14:00:00Z',
            locksAt: '2026-11-02T14:00:00Z',
            form: { infraction: 'Missed practice.', discussion: 'We met.', plan_authority: 'Check in weekly.', consequences: ['nfa'] },
        };
        const trooperCan = { ...props().can, edit: false, delete: false, invite: false, share: false, respond: true, fileLor: false };

        it('shows the countdown and what the leader wrote', () => {
            const wrapper = page({ record: shared, can: trooperCan });

            expect(wrapper.find('[data-testid="respond-countdown"]').text()).toContain('2 h 0 min left');
            expect(wrapper.find('[data-testid="letter-discussion"]').text()).toContain('We met.');
            expect(wrapper.find('[data-testid="letter-consequences"]').text()).toContain('30 days no favorable action');
            expect(wrapper.find('[data-testid="back-to-trooper"]').exists()).toBe(false);
        });

        it('needs a choice, and a reason to appeal, then submits once', async () => {
            const wrapper = page({ record: shared, can: trooperCan });

            expect(wrapper.find('[data-testid="respond-submit"]').attributes('disabled')).toBeDefined();
            await wrapper.find('[data-testid="respond-plan"]').setValue('Post an LOA.');
            await wrapper.find('[data-testid="respond-appeal-do"]').setValue(true);
            expect(wrapper.text()).toContain('CPT Halvorsen.R decides within 72 hours.');

            await wrapper.find('[data-testid="respond-form"]').trigger('submit');
            expect(sent).not.toHaveBeenCalled();
            expect(wrapper.find('[data-testid="respond-reason-error"]').text()).toBe('Say why you appeal.');

            await wrapper.find('[data-testid="respond-reason"]').setValue('An LOR is too much.');
            await wrapper.find('[data-testid="respond-form"]').trigger('submit');
            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/respond', {
                plan_trooper: 'Post an LOA.',
                comments_trooper: '',
                appeal: 'do',
                appeal_reason: 'An LOR is too much.',
            });
        });

        it('asks for PM permission only when the trooper appeals', async () => {
            const wrapper = page({ record: shared, can: trooperCan, pm: { allowed: false, consentUrl: '/auth/forum-pm' } });

            await wrapper.find('[data-testid="respond-appeal-do_not"]').setValue(true);
            expect(wrapper.find('[data-testid="pm-consent"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="respond-submit"]').attributes('disabled')).toBeUndefined();

            await wrapper.find('[data-testid="respond-appeal-do"]').setValue(true);
            expect(wrapper.find('[data-testid="pm-consent-link"]').attributes('href')).toBe('/auth/forum-pm');
            expect(wrapper.find('[data-testid="respond-submit"]').attributes('disabled')).toBeDefined();
        });

        it('sends only the plan and comments on a DCS', async () => {
            const wrapper = page({ record: { ...shared, type: 'dcs', typeLabel: 'Developmental Counseling Statement' }, can: trooperCan });

            expect(wrapper.find('[data-testid="respond-appeal"]').exists()).toBe(false);
            await wrapper.find('[data-testid="respond-comments"]').setValue('Understood.');
            await wrapper.find('[data-testid="respond-form"]').trigger('submit');
            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/respond', { plan_trooper: '', comments_trooper: 'Understood.' });
        });
    });

    describe('the shared record, read-only', () => {
        const appealed = {
            state: 'decided',
            status: 'shared',
            date: '2026-10-29',
            sharedAt: '2026-10-29T22:14:00Z',
            respondBy: '2026-10-30T22:14:00Z',
            respondedAt: '2026-10-30T09:12:00Z',
            locksAt: '2026-11-01T22:14:00Z',
            frozen: true,
            form: { infraction: 'Missed practice.', plan_trooper: 'Post an LOA.', appeal: 'do', consequences: ['nfa'] },
            appeal: {
                at: '2026-10-30T09:12:00Z',
                reason: 'My shifts changed.',
                dueAt: '2026-11-02T09:12:00Z',
                pending: false,
                decided: { at: '2026-10-31T10:00:00Z', outcome: 'not_amended', outcomeLabel: 'Have not amended', reason: 'It stands.', authority: null, by: 'Halvorsen.R', original: null },
            },
        };
        const chainCan = { ...props().can, edit: false, delete: false, invite: false, share: false };

        it('shows the appeal, its decision and the trooper’s part, with no draft controls', () => {
            const wrapper = page({ record: appealed, can: chainCan });

            expect(wrapper.find('[data-testid="counseling-draft"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="counseling-state"]').text()).toBe('Decided');
            expect(wrapper.find('[data-testid="appeal-reason"]').text()).toBe('My shifts changed.');
            expect(wrapper.find('[data-testid="appeal-decision"]').text()).toContain('Have not amended');
            expect(wrapper.find('[data-testid="letter-plan_trooper"]').text()).toContain('Post an LOA.');
            expect(wrapper.find('[data-testid="form-preview-outcome"]').text()).toContain('HAVE NOT AMENDED');
            expect(wrapper.find('[data-testid="counseling-withdraw"]').exists()).toBe(false);
        });

        it('withdraws with a reason, and only with PM permission', async () => {
            const shared = { ...appealed, state: 'shared', frozen: false, appeal: null, respondedAt: null };
            expect(page({ record: shared, can: { ...chainCan, withdraw: true }, pm: { allowed: false, consentUrl: '/x' } }).find('[data-testid="counseling-withdraw"]').attributes('disabled')).toBeDefined();
            document.body.innerHTML = '';

            const wrapper = page({ record: shared, can: { ...chainCan, withdraw: true } });
            await wrapper.find('[data-testid="counseling-withdraw"]').trigger('click');
            await flushPromises();
            const reason = document.querySelector('[data-testid="withdraw-dialog-reason"]');
            reason.value = 'Wrong date of letter.';
            reason.dispatchEvent(new Event('input'));
            await flushPromises();
            document.querySelector('[data-testid="withdraw-dialog-confirm"]').click();
            await flushPromises();

            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/withdraw', { reason: 'Wrong date of letter.' });
        });

        it('shows the withdrawal reason on a record withdrawn to draft', () => {
            const wrapper = page({ record: { withdrawn: { at: '2026-10-30T10:00:00Z', reason: 'Wrong date.' } } });

            expect(wrapper.find('[data-testid="counseling-state"]').text()).toBe('Withdrawn to draft');
            expect(wrapper.find('[data-testid="draft-withdrawn"]').text()).toContain('Wrong date.');
        });
    });

    describe('a record kept with its source PDF', () => {
        it('sends the reviewed fields to the review route', async () => {
            const wrapper = page({
                record: { state: 'shared', status: 'shared', source: 'import', needsReview: true, hasSourceDocument: true, type: 'dcs', typeLabel: 'Developmental Counseling Statement', kind: null, form: {} },
                can: { ...props().can, edit: false, delete: false, share: false, invite: false, review: true },
                missing: [],
            });

            await wrapper.find('[data-testid="review-kind"]').setValue('conduct');
            await wrapper.find('[data-testid="review-date"]').setValue('2025-03-12');
            await wrapper.find('[data-testid="review-infraction"]').setValue('Late twice.');
            await wrapper.find('[data-testid="review-comments_trooper"]').setValue('Noted.');
            await wrapper.find('[data-testid="counseling-review"]').trigger('submit');

            expect(sent).toHaveBeenCalledWith('post', '/counseling/42/review', {
                type: 'dcs',
                kind: 'conduct',
                counseled_on: '2025-03-12',
                form: {
                    infraction: 'Late twice.',
                    regulation: '',
                    discussion: '',
                    plan_authority: '',
                    plan_other: '',
                    comments_authority: '',
                    comments_other: '',
                    plan_trooper: '',
                    comments_trooper: 'Noted.',
                },
            });
        });
    });
});
