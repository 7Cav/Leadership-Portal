import '@fontsource-variable/geist';
import '@fontsource-variable/geist-mono';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './Layouts/AppLayout.vue';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    // Every authenticated page renders inside the persistent app shell (Story 1.15). Auth/ pages
    // (the login screen) are the only ones outside it: there is no session to show a shell for.
    layout: (name) => (name.startsWith('Auth/') ? null : AppLayout),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
