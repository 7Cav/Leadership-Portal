import '@fontsource-variable/geist';
import '@fontsource-variable/geist-mono';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { resolveLayout } from './Layouts/resolveLayout';

createInertiaApp({
    // Pages load on demand, so each one builds to its own chunk instead of one bundle.
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    layout: resolveLayout,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
