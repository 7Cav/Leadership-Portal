<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

// Minimal authenticated landing (Story 1.4). Real leadership screens land in later epics.
const page = usePage();
// `||`, not `??`: an empty username would otherwise render a blank identity and a blank
// monogram rather than falling back.
const username = computed(() => page.props.auth?.user?.username || 'Trooper');

// Avatar monogram, matching the mockup top-nav's two-letter initials (`HS` for Hale.S).
const initials = computed(() =>
    username.value
        .split(/[.\s_-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join(''),
);
</script>

<template>
    <Head title="Home · 7Cav Leadership Portal" />

    <div class="min-h-screen bg-bg text-white">
        <!--
          The top-nav `.user` cluster (Story 1.5): identity on the right with the avatar
          monogram, mirroring the mockups' persistent nav, and carrying the sign-out
          control. The brand and tab row arrive with the real nav in a later UI story.
        -->
        <nav class="flex items-center border-b border-border px-6">
            <div class="user ml-auto flex items-center gap-3 py-3 text-[13px] text-gray-400">
                <span>{{ username }}</span>
                <span
                    class="grid h-7 w-7 place-items-center rounded-full border border-border bg-panel-2 text-xs text-gold"
                    aria-hidden="true"
                >
                    {{ initials }}
                </span>
                <!--
                  A POST, not a link: ending a session is a state change, so it goes through
                  CSRF protection (AC #5). Inertia attaches the XSRF header itself.
                -->
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    type="button"
                    class="rounded border border-border px-3 py-1 hover:border-gold-soft hover:text-gold"
                >
                    Sign out
                </Link>
            </div>
        </nav>

        <main class="flex items-center justify-center p-8">
            <div class="w-full max-w-lg rounded-lg border border-border bg-panel p-8">
                <span class="inline-block rounded bg-gold px-3 py-1 text-sm font-semibold text-bg">
                    Signed in
                </span>
                <h1 class="mt-4 text-2xl font-semibold text-gold">Welcome, {{ username }}.</h1>
                <p class="mt-2 text-sm text-gray-400">
                    You're authenticated with 7Cav SSO. Leadership tools land here as the portal grows.
                </p>
            </div>
        </main>
    </div>
</template>
