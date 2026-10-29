import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Blocked from '../Blocked.vue';

// `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), Head: { template: '<head></head>' } }));

describe('Blocked', () => {
    it.each([
        ['viewAs', 'trooper', 'The trooper you are viewing as does not have permission to see this page.', 'Exit view as'],
        ['viewAs', 'unit', 'The unit you are viewing as does not have permission to see this page.', 'Exit view as'],
        ['actAs', 'trooper', 'The trooper you are acting as does not have permission to see this page.', 'Stop acting'],
        ['actAs', 'billet', 'The billet you are acting as does not have permission to see this page.', 'Stop acting'],
    ])('%s of a %s says the page is blocked for them', (mode, kind, message, way) => {
        const page = mount(Blocked, { props: { mode, kind } });

        expect(page.get('[data-slot="empty-state"]').text()).toContain(message);
        expect(page.text()).toContain(`Use ${way} in the banner to see it as yourself.`);
    });
});
