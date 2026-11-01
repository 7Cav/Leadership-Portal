import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MissingIdentityIcon from '../MissingIdentityIcon.vue';
import MissingIdentityNotice from '../MissingIdentityNotice.vue';
import TrooperCard from '../rollcall/TrooperCard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const row = (extra = {}) => ({ id: 1, cavId: 1, rankShort: 'PFC', username: 'Ortega.M', rollcall: 'unsigned', status: null, source: null, ...extra });

describe('MissingIdentityIcon', () => {
    it('says which game is missing and why it matters', () => {
        const icon = mount(MissingIdentityIcon, { props: { game: 'Hell Let Loose: Vietnam' } }).find('[data-testid="missing-identity"]');

        expect(icon.attributes('title')).toBe("No Hell Let Loose: Vietnam identity linked, so the game server can't see them");
        expect(icon.attributes('aria-label')).toBe(icon.attributes('title'));
    });

    it('draws nothing without a game', () => {
        expect(mount(MissingIdentityIcon).find('[data-testid="missing-identity"]').exists()).toBe(false);
    });
});

describe('TrooperCard missing identity', () => {
    it('marks only the row whose trooper is missing an identity', () => {
        const missing = mount(TrooperCard, { props: { trooper: row({ missingGame: 'Hell Let Loose: Vietnam' }), kind: 'signature' } });
        const linked = mount(TrooperCard, { props: { trooper: row({ missingGame: null }), kind: 'signature' } });

        expect(missing.find('[data-testid="missing-identity"]').exists()).toBe(true);
        expect(linked.find('[data-testid="missing-identity"]').exists()).toBe(false);
    });
});

describe('MissingIdentityNotice', () => {
    it('counts the troopers missing an identity and links to Game identities', () => {
        const wrapper = mount(MissingIdentityNotice, { props: { troopers: [row({ missingGame: 'Arma Reforger' }), row({ id: 2, missingGame: 'Arma Reforger' }), row({ id: 3 })] } });
        const notice = wrapper.find('[data-testid="missing-identities-banner"]');

        expect(notice.text()).toContain('2 troopers have no Arma Reforger identity linked');
        expect(notice.find('a').attributes('href')).toBe('/game-identities');
    });

    it('stays hidden when everyone is linked', () => {
        expect(mount(MissingIdentityNotice, { props: { troopers: [row()] } }).find('[data-testid="missing-identities-banner"]').exists()).toBe(false);
    });

    it('drops the consequence for a game the server does not read', () => {
        const manual = mount(MissingIdentityNotice, { props: { troopers: [row({ missingGame: 'Arma Reforger', missingGameAutomatic: false })] } });
        const read = mount(MissingIdentityNotice, { props: { troopers: [row({ missingGame: 'Arma Reforger', missingGameAutomatic: true })] } });

        expect(manual.find('[data-testid="missing-identity-consequence"]').exists()).toBe(false);
        expect(read.find('[data-testid="missing-identity-consequence"]').exists()).toBe(true);
    });
});
