import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    // `@/` → resources/js, the alias shadcn-vue's generated components import through
    // (components.json, jsconfig.json).
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        // Vite's default, written out so the hot file names `localhost` rather than the address it bound to: on a
        // machine where that is `::1`, the URL would be `http://[::1]:5173`, and a content security policy cannot
        // name an IPv6 address, so the dev server's scripts would be refused (SetSecurityHeaders, #485).
        host: 'localhost',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
