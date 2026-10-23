<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { PhBroadcast, PhCircleNotch, PhUserPlus, PhUsers, PhX } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import RankBadge from '@/components/roster/RankBadge.vue';
import { DEBOUNCE_MS, MAX_CHARS, MIN_CHARS } from '@/composables/useTrooperSearch';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { postJson, reloadPage, sessionEnded } from '@/lib/postJson';

// The practice step's "Guests" area (JSAM evidence, decided 2026-10-23): troopers from other sections who attended
// this section's practice, as the leader adds them by hand (the game server records guests again once practices have
// set times; its older sightings still show, marked as such). A hand-added guest gets an automatic makeup on their own
// section's rollcall; only a hand-added one can be undone. The server decides who may add (the section's write
// scope), finds the guests (outside the leader's scope, name, rank and section only) and refuses a locked or confirmed
// week; this only shows its answers.
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The practice's week (`YYYY-MM-DD`).
    week: { type: String, required: true },
    // `[{ trooperId, name, displayName, rank, section, source: 'capture' | 'manual', recordedBy }]`.
    guests: { type: Array, default: () => [] },
    editable: { type: Boolean, default: true },
    // Why nothing here can be changed (a preview, a staff grant, a locked or confirmed week), shown with the controls.
    disabledReason: { type: String, default: null },
});

const list = ref([...props.guests]);
watch(
    () => props.guests,
    (guests) => (list.value = [...guests]),
);

const MAKEUP_ADDED = {
    marked: 'A makeup is on their own section\'s rollcall for this week.',
    kept: 'Their own rollcall already had a mark for this week, so it stayed.',
    locked: 'Their own section\'s week is locked, so no makeup was added there.',
};
const MAKEUP_REMOVED = {
    withdrawn: 'The makeup it put on their own rollcall was taken back.',
    kept: 'Their own rollcall mark stays as it is.',
    locked: 'Their own section\'s week is locked, so its mark stays.',
};

// The picker: closed until "Add guest", then a username search with a pause before each request, as the shell's.
const adding = ref(false);
const query = ref('');
const status = ref('idle'); // idle | loading | ready | error | throttled | tooLong
const results = ref([]);
const more = ref(false);
const busy = ref(null); // the trooper id being added or removed
let timer = null;
let controller = null;
let latest = 0;

// Characters as the server counts them (`mb_strlen`, one per code point), not as `String.length` does (UTF-16 units),
// so the picker sends exactly the searches the server accepts, as the shell's search does.
const charCount = (text) => [...text].length;

function clearResults(next) {
    results.value = [];
    more.value = false;
    status.value = next;
}

function cancel() {
    clearTimeout(timer);
    controller?.abort();
    controller = null;
}
onBeforeUnmount(cancel);

async function search(q) {
    const id = ++latest;
    controller = new AbortController();
    try {
        const response = await fetch(`/rollcall/${props.sectionId}/guests/search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        if (id !== latest) return;
        if (sessionEnded(response.status)) {
            reloadPage();
            return;
        }
        if (response.status === 429) {
            status.value = 'throttled';
            return;
        }
        // The server's length check (2 to 64 characters) is not a broken connection. Only a search counted in range is
        // sent, so a refusal means the server counted more (a space PHP's trim keeps): say too long.
        if (response.status === 422) {
            clearResults(charCount(q) < MIN_CHARS ? 'idle' : 'tooLong');
            return;
        }
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body = await response.json();
        if (id !== latest) return;
        const listed = new Set(list.value.map((guest) => guest.trooperId));
        results.value = body.troopers.map((trooper) => ({ ...trooper, listed: listed.has(trooper.id) }));
        more.value = body.more;
        status.value = 'ready';
    } catch (error) {
        if (error?.name === 'AbortError' || id !== latest) return;
        status.value = 'error';
    }
}

watch(query, (value) => {
    cancel();
    const q = value.trim();
    const length = charCount(q);
    if (length < MIN_CHARS || length > MAX_CHARS) {
        latest++;
        clearResults(length > MAX_CHARS ? 'tooLong' : 'idle');
        return;
    }
    status.value = 'loading';
    timer = setTimeout(() => search(q), DEBOUNCE_MS);
});

function closePicker() {
    cancel();
    adding.value = false;
    query.value = '';
}

const label = (guest) => guest.displayName ?? guest.name;

async function add(trooper) {
    if (!props.editable || busy.value !== null || trooper.listed) return;
    busy.value = trooper.id;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/guests`, { week: props.week, trooper_id: trooper.id }).catch(unreachable);
        if (!result.ok) {
            toast.error(`Could not add ${trooper.name}`, { description: messageFor(result) });
            return;
        }
        const guest = result.data.guest;
        list.value = [...list.value.filter((g) => g.trooperId !== guest.trooperId), guest].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? '', undefined, { sensitivity: 'base' }));
        toast.success(`Added ${label(guest)} as a guest`, { description: MAKEUP_ADDED[result.data.makeup] ?? MAKEUP_ADDED.marked });
        closePicker();
    } finally {
        busy.value = null;
    }
}

async function undo(guest) {
    if (!props.editable || busy.value !== null || guest.source !== 'manual') return;
    busy.value = guest.trooperId;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/guests/remove`, { week: props.week, trooper_id: guest.trooperId }).catch(unreachable);
        if (!result.ok) {
            toast.error(`Could not remove ${label(guest)}`, { description: messageFor(result) });
            return;
        }
        list.value = list.value.filter((g) => g.trooperId !== guest.trooperId);
        toast.success(`Removed ${label(guest)}`, { description: MAKEUP_REMOVED[result.data.makeup] ?? MAKEUP_REMOVED.kept });
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Card class="mt-5 gap-0 overflow-hidden py-0 text-[13px]" data-testid="guests-panel" aria-labelledby="guests-title">
        <div class="flex flex-wrap items-start gap-x-3 gap-y-2 border-b border-border px-4 py-3">
            <div class="min-w-0 flex-1">
                <h3 id="guests-title" class="flex items-center gap-2 text-sm font-semibold">
                    <PhUsers class="size-4 text-muted-foreground" aria-hidden="true" />Guests at this practice
                    <span class="text-xs font-normal text-muted-foreground tabular-nums">{{ list.length }}</span>
                </h3>
                <p class="mt-0.5 max-w-[62ch] text-xs text-muted-foreground">
                    Add troopers from other sections who attended. Each one counts as a makeup on their own section's rollcall. The
                    game server doesn't add guests yet; it will once practices have set times.{{
                        disabledReason ? ` ${disabledReason}.` : ''
                    }}
                </p>
            </div>
            <Button
                v-if="!adding"
                variant="outline"
                size="sm"
                data-testid="guest-add"
                :disabled="!editable"
                :title="disabledReason ?? undefined"
                @click="adding = true"
            >
                <PhUserPlus />Add guest
            </Button>
        </div>

        <div v-if="adding && editable" class="border-b border-border px-4 py-3" data-testid="guest-picker">
            <div class="flex items-center gap-2">
                <Input
                    v-model="query"
                    autofocus
                    autocomplete="off"
                    spellcheck="false"
                    :maxlength="MAX_CHARS"
                    placeholder="Find a trooper from another section by name"
                    aria-label="Find a guest by name"
                    data-testid="guest-search"
                />
                <Button variant="ghost" size="icon-sm" aria-label="Close the guest search" @click="closePicker"><PhX /></Button>
            </div>
            <p v-if="status === 'idle'" class="mt-2 text-xs text-muted-foreground">Type at least {{ MIN_CHARS }} letters of a name.</p>
            <p v-else-if="status === 'loading'" class="mt-2 inline-flex items-center gap-1.5 text-xs text-muted-foreground" aria-busy="true">
                <PhCircleNotch class="size-3.5 animate-spin" aria-hidden="true" />Searching
            </p>
            <p v-else-if="status === 'error'" role="alert" class="mt-2 text-xs text-destructive" data-testid="guest-search-error">
                The search did not load. Check your connection, then type again.
            </p>
            <p v-else-if="status === 'tooLong'" class="mt-2 text-xs text-muted-foreground" data-testid="guest-search-too-long">
                That is longer than any name. Search with at most {{ MAX_CHARS }} characters.
            </p>
            <p v-else-if="status === 'throttled'" role="alert" class="mt-2 text-xs text-muted-foreground">Too many searches in a short time. Wait a moment, then keep typing.</p>
            <p v-else-if="!results.length" class="mt-2 text-xs text-muted-foreground" data-testid="guest-search-empty">
                No serving trooper in another section matches “{{ query.trim() }}”.
            </p>
            <ul v-else class="mt-2 divide-y divide-border rounded-md border border-border" data-testid="guest-results">
                <li v-for="trooper in results" :key="trooper.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2.5 px-2.5 py-2 text-left hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busy !== null || trooper.listed"
                        :data-testid="`guest-result-${trooper.id}`"
                        @click="add(trooper)"
                    >
                        <RankBadge :rank="trooper.rank" />
                        <span class="min-w-0 truncate font-medium">{{ trooper.name }}</span>
                        <span class="ml-auto shrink-0 font-mono text-xs text-muted-foreground">
                            <template v-if="trooper.listed">Already listed</template>
                            <template v-else>{{ trooper.section ?? 'No section' }}</template>
                        </span>
                        <PhCircleNotch v-if="busy === trooper.id" class="size-3.5 animate-spin" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <p v-if="status === 'ready' && more" class="mt-1.5 text-xs text-muted-foreground">Showing the first {{ results.length }}. Type more of the name to narrow it down.</p>
        </div>

        <ul v-if="list.length" class="divide-y divide-border" data-testid="guest-list">
            <li v-for="guest in list" :key="guest.trooperId" class="flex flex-wrap items-center gap-x-2.5 gap-y-1 px-4 py-2.5" :data-testid="`guest-${guest.trooperId}`">
                <RankBadge :rank="guest.rank" />
                <span class="min-w-0 truncate font-medium">{{ guest.name }}</span>
                <span class="font-mono text-xs text-muted-foreground">{{ guest.section ?? 'No section' }}</span>
                <Badge v-if="guest.source === 'capture'" variant="outline" class="font-normal" data-testid="guest-source">
                    <PhBroadcast aria-hidden="true" />Seen on the server
                </Badge>
                <Badge v-else variant="outline" class="font-normal" data-testid="guest-source">
                    <template v-if="guest.recordedBy">Added by {{ guest.recordedBy }}</template><template v-else>Added by hand</template>
                </Badge>
                <Button
                    v-if="guest.source === 'manual' && editable"
                    variant="outline"
                    size="xs"
                    class="ml-auto"
                    data-testid="guest-undo"
                    :disabled="busy !== null"
                    :aria-label="`Undo adding ${guest.name}`"
                    @click="undo(guest)"
                >
                    <PhCircleNotch v-if="busy === guest.trooperId" class="animate-spin" aria-hidden="true" />Undo
                </Button>
            </li>
        </ul>
        <p v-else class="px-4 py-3 text-xs text-muted-foreground" data-testid="guest-empty">No guests recorded for this practice.</p>
    </Card>
</template>
