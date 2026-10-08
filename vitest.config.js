import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';

// Vitest runs Vue component unit tests in isolation — no Laravel/Vite dev server,
// no PHP, no DB. The Laravel plugin is intentionally omitted (it resolves a build
// manifest that doesn't exist under test). Browser-level checks live in Playwright.
export default defineConfig({
    plugins: [vue()],
    // `@/` → resources/js, the alias shadcn-vue's generated components import through
    // (components.json, jsconfig.json).
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.{test,spec}.js'],
        globals: true,
    },
});
