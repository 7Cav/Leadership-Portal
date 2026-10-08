import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// The signed-in identity as the shell shows it (moved out of Home.vue in Story 1.15; the rules
// below are Story 1.5–1.7's). Reads the shared `auth` prop from HandleInertiaRequests.
export function useIdentity() {
    const page = usePage();

    // `||`, not `??`: an empty username would otherwise render a blank identity and a blank
    // monogram rather than falling back.
    const username = computed(() => page.props.auth?.user?.username || 'Trooper');

    // Whether the signed-in user really is an Admin. The server's own gates read this flag.
    const isRealAdmin = computed(() => page.props.auth?.user?.is_admin === true);

    // Whether the shell should draw Admin-only chrome. During a view-as preview (Story 1.13) the
    // shell shows what the previewed leader would see, so it follows the preview: a unit's leader
    // is never an Admin, a previewed person is one only if they really are.
    const isAdmin = computed(() => {
        const viewAs = page.props.viewAs;

        return viewAs ? viewAs.admin === true : isRealAdmin.value;
    });

    // Cav roster context for this session (Story 1.6). Null when the trooper holds no milpac or
    // the roster API was unavailable; the cluster then degrades to the bare username.
    const profile = computed(() => page.props.auth?.profile ?? null);

    // `WO1 Hale.S`. Rank and billet are each optional: a trooper mid-transfer can hold a rank with
    // no billet, so neither is allowed to drag the other out.
    const name = computed(() => [profile.value?.rank_short, username.value].filter(Boolean).join(' '));
    const billet = computed(() => profile.value?.billet || null);

    // `WO1 Hale.S · S1 Personnel NCO`, the one-line form.
    const identity = computed(() => (billet.value ? `${name.value} · ${billet.value}` : name.value));

    // AD-20 rule 1 / NFR7: a synced value never renders without its source and age, so a rank the
    // roster changed an hour ago can't read as something the portal knows for certain.
    const fetchedAt = computed(() => {
        if (!profile.value?.fetched_at) return null;

        const at = new Date(profile.value.fetched_at);

        return Number.isNaN(at.getTime()) ? null : at;
    });

    // The snapshot is taken once per login and the session lives for 24h (SESSION_LIFETIME=1440),
    // so a bare `09:14` would read as "an hour ago" the next morning. Show the date too whenever
    // the fetch was not today: the point of AD-20 is that age is legible.
    const fetchedAtLabel = computed(() => {
        const at = fetchedAt.value;
        if (!at) return null;

        const time = at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        const isToday = at.toDateString() === new Date().toDateString();

        return isToday ? time : `${at.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} ${time}`;
    });

    // MOS shares this line: it is the same synced value from the same fetch, so one source-and-time
    // stamp covers rank, billet and MOS together.
    const provenance = computed(() => {
        if (!profile.value?.source) return null;

        const mos = profile.value.mos ? `MOS ${profile.value.mos}` : null;

        return [mos, profile.value.source, fetchedAtLabel.value].filter(Boolean).join(' · ');
    });

    // The hover detail only adds to the visible line (full rank title, exact timestamp); removing
    // it must never hide the attribution.
    const provenanceDetail = computed(
        () =>
            [profile.value?.rank_full, fetchedAt.value && `fetched ${fetchedAt.value.toLocaleString()}`]
                .filter(Boolean)
                .join(', ') || null,
    );

    // Avatar monogram: two letters from the username (`HS` for Hale.S).
    const initials = computed(() =>
        username.value
            .split(/[.\s_-]+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join(''),
    );

    return { username, isAdmin, isRealAdmin, name, billet, identity, provenance, provenanceDetail, initials };
}
