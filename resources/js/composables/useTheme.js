import { readonly, ref } from 'vue';

// The viewer's light/dark choice (Story 1.15). One module-level ref, so the header toggle and the
// user menu's Light/Dark switch can never disagree. The `.dark` class on <html> is what the CSS
// tokens key off (resources/css/app.css); app.blade.php sets it before first paint from the same
// storage key so a dark-mode user never sees a light flash.
export const THEME_STORAGE_KEY = 'portal-theme';

function currentTheme() {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

const theme = ref(currentTheme());

export function useTheme() {
    function setTheme(value) {
        const next = value === 'dark' ? 'dark' : 'light';

        theme.value = next;
        document.documentElement.classList.toggle('dark', next === 'dark');

        // Storage can be blocked (private mode, disabled site data). The choice still applies to
        // this page; it just will not survive a reload, which is the best available outcome.
        try {
            localStorage.setItem(THEME_STORAGE_KEY, next);
        } catch {
            // Intentionally ignored: see above.
        }
    }

    function toggleTheme() {
        setTheme(theme.value === 'dark' ? 'light' : 'dark');
    }

    // Re-read the DOM, for when <html> was themed before this module loaded (tests, pre-paint).
    function syncFromDocument() {
        theme.value = currentTheme();
    }

    return { theme: readonly(theme), setTheme, toggleTheme, syncFromDocument };
}
