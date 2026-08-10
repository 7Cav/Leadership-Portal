<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

// Minimal authenticated landing (Story 1.4). Real leadership screens land in later epics.
const page = usePage();
// `||`, not `??`: an empty username would otherwise render a blank identity and a blank
// monogram rather than falling back.
const username = computed(() => page.props.auth?.user?.username || 'Trooper');

// Cav roster context for this session (Story 1.6). Null when the trooper holds no milpac or
// the roster API was unavailable — the cluster then degrades to the bare username.
const profile = computed(() => page.props.auth?.profile ?? null);

// `WO1 Hale.S · S1`, per the mockup top-nav. Rank and billet are each optional: a trooper
// mid-transfer can hold a rank with no billet, so neither is allowed to drag the other out.
const identity = computed(() => {
    const ranked = [profile.value?.rank_short, username.value].filter(Boolean).join(' ');

    return profile.value?.billet ? `${ranked} · ${profile.value.billet}` : ranked;
});

// AD-20 rule 1 / NFR7: a synced value never renders without its source and age, so a rank the
// roster changed an hour ago can't read as something the portal knows for certain. Shown, not
// hidden in a tooltip — the tooltip only adds the full rank title and the exact timestamp.
const fetchedAt = computed(() => {
    if (!profile.value?.fetched_at) return null;

    const at = new Date(profile.value.fetched_at);

    return Number.isNaN(at.getTime()) ? null : at;
});

// The snapshot is taken once per login and the session lives for 24h (SESSION_LIFETIME=1440),
// so a bare `09:14` would read as "an hour ago" to someone looking at it the next morning. Show
// the date too whenever the fetch was not today — the point of AD-20 is that age is legible,
// and a time-only stamp on a day-old value hides exactly what it is meant to reveal.
const fetchedAtLabel = computed(() => {
    const at = fetchedAt.value;
    if (!at) return null;

    const time = at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    const isToday = at.toDateString() === new Date().toDateString();

    return isToday ? time : `${at.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} ${time}`;
});

// MOS shares this line rather than getting its own: it is the same synced value from the same
// fetch, so one source-and-time stamp covers rank, billet and MOS together.
const provenance = computed(() => {
    if (!profile.value?.source) return null;

    const mos = profile.value.mos ? `MOS ${profile.value.mos}` : null;

    return [mos, profile.value.source, fetchedAtLabel.value].filter(Boolean).join(' · ');
});

const provenanceDetail = computed(() =>
    [profile.value?.rank_full, fetchedAt.value && `fetched ${fetchedAt.value.toLocaleString()}`]
        .filter(Boolean)
        .join(' — ') || null,
);

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
                <span class="flex flex-col items-end leading-tight">
                    <span class="identity">{{ identity }}</span>
                    <!--
                      Provenance line. Rendered only when there is something to attribute, so
                      an unenriched session shows a plain username rather than an empty claim.
                    -->
                    <span
                        v-if="provenance"
                        class="provenance text-[11px] text-gray-500"
                        :title="provenanceDetail ?? undefined"
                    >
                        {{ provenance }}
                    </span>
                </span>
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
