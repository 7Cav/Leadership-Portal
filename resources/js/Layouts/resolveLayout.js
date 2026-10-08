import AppLayout from './AppLayout.vue';

// The default layout for an Inertia page name (Story 1.15). Every authenticated page renders
// inside the persistent app shell; Auth/ pages (the login screen) are the only ones outside it,
// because there is no session to show a shell for.
export function resolveLayout(name) {
    return name.startsWith('Auth/') ? null : AppLayout;
}
