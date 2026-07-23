import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

// Vitest runs Vue component unit tests in isolation — no Laravel/Vite dev server,
// no PHP, no DB. The Laravel plugin is intentionally omitted (it resolves a build
// manifest that doesn't exist under test). Browser-level checks live in Playwright.
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.{test,spec}.js'],
        globals: true,
    },
});
