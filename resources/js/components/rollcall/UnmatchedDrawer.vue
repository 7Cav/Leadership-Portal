<script setup>
import { computed, reactive, ref } from 'vue';
import { PhCaretRight, PhCircleNotch, PhUserCircleDashed } from '@phosphor-icons/vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/native-select';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { postJson } from '@/lib/postJson';
import { fullName } from '@/lib/rollcall';
import { suggestTroopers } from '@/lib/unmatched';

// The "Not matched to a trooper" drawer (Story 7.7), binding design mockups/v2/capture-review.html
// variant C: every player the capture saw but no trooper is linked to, each with a trooper picker
// and two actions. The picker only *orders* by name closeness: nothing is ever chosen for the
// leader, and "Link and mark present" stays disabled until they choose (AD-19). The server does the
// work (an audited link, then the manual-wins pre-mark) and scope-gates both actions (AD-8). A trooper who already
// holds another ID on the game is never overwritten silently: the leader is asked to replace or cancel (#176).
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The practice's week (`YYYY-MM-DD`).
    week: { type: String, required: true },
    // `[{ platform, platformLabel, playerName, platformId }]`.
    players: { type: Array, required: true },
    // The section's troopers, as the rollcall rows: `[{ id, name, displayName, ... }]`.
    troopers: { type: Array, required: true },
    editable: { type: Boolean, default: true },
    disabledReason: { type: String, default: null },
    // Called with the chosen trooper id before a link is sent; resolves false to hold the link back
    // (the rollcall saves the leader's pending marks first, so the pre-mark cannot race them).
    beforeLink: { type: Function, default: null },
    // Players this practice's capture saw that a leader dismissed (#176), in the `players` shape, listed under
    // "Dismissed" with an Undo each.
    dismissed: { type: Array, default: () => [] },
    // Undoes one dismissal (the rollcall's, shared with its toast): resolves true once the player is listed again.
    restore: { type: Function, default: null },
});

// `linked` carries the server's answer ({ marked, trooper, atRisk }); `dismissed` the player.
const emit = defineEmits(['linked', 'dismissed']);

const keyOf = (player) => `${player.platform}:${player.platformId}`;

// Per player: the chosen trooper id ('' = none), a request in flight, the inline error, and
// whether the dismissal is waiting for its confirmation.
const state = reactive({});
const of = (player) => (state[keyOf(player)] ??= { trooperId: '', busy: false, error: null, confirming: false });

const options = computed(() =>
    Object.fromEntries(
        props.players.map((player) => {
            const suggested = suggestTroopers(player.playerName, props.troopers);
            const ids = new Set(suggested.map((t) => t.id));

            return [keyOf(player), { suggested, rest: props.troopers.filter((t) => !ids.has(t.id)) }];
        }),
    ),
);

const body = (player) => ({ week: props.week, platform: player.platform, platform_id: player.platformId });

// "Replace or cancel" (#176): the server refused a link because the chosen trooper already holds a different
// identity on that game, and said which (`reason: has_other_identity`, `current`). Nothing is overwritten until the
// leader answers: Replace sends the same link again with `replace: true` and `replacing`, the ID the dialog showed,
// so the server overwrites only that one; Cancel leaves everything as it was. If the trooper's ID changed in the
// meantime the server refuses the replace with the new `current`, and the dialog asks again with it.
// `{ player, trooperId, current: { playerName, platformId } }` while the question is open, else null.
const replacing = ref(null);
const trooperName = (id) => {
    const trooper = props.troopers.find((t) => t.id === id);

    return trooper ? fullName(trooper) : `trooper ${id}`;
};

// `replace`: the platform ID the leader agreed to replace, or null for a plain link.
async function link(player, replace = null) {
    const s = of(player);
    if (!props.editable || s.busy || s.trooperId === '') return;

    s.busy = true;
    s.error = null;
    try {
        const trooperId = Number(s.trooperId);
        if (props.beforeLink && !(await props.beforeLink(trooperId))) {
            s.error = 'Your marks are not saved yet, so the player was not linked. Retry saving them, then link again.';

            return;
        }
        const result = await postJson(`/rollcall/${props.sectionId}/unmatched/link`, {
            ...body(player),
            trooper_id: trooperId,
            ...(replace !== null ? { replace: true, replacing: replace } : {}),
        }).catch(unreachable);
        const current = result.data?.reason === 'has_other_identity' ? result.data.current : null;
        if (result.ok) emit('linked', { player, ...result.data });
        // Asked for the first time, or asked again because the ID changed since the leader answered. The same ID
        // refused twice would only loop the question, so it shows inline instead.
        else if (current && current.platformId !== replace) replacing.value = { player, trooperId, current };
        else s.error = messageFor(result);
    } finally {
        s.busy = false;
    }
}

// The dismissals being undone right now, by key, so each Undo shows it is busy and cannot be sent twice.
const restoring = reactive(new Set());

async function undoDismissal(player) {
    const key = keyOf(player);
    if (!props.editable || !props.restore || restoring.has(key)) return;

    restoring.add(key);
    try {
        await props.restore(player);
    } finally {
        restoring.delete(key);
    }
}

function cancelReplace() {
    replacing.value = null;
}

// The leader chose Replace: the same player and trooper as asked about, even if the picker moved since.
async function confirmReplace() {
    const ask = replacing.value;
    replacing.value = null;
    if (!ask) return;
    of(ask.player).trooperId = String(ask.trooperId);
    await link(ask.player, ask.current.platformId);
}

async function dismiss(player) {
    const s = of(player);
    if (!props.editable || s.busy) return;

    s.busy = true;
    s.error = null;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/unmatched/dismiss`, body(player)).catch(unreachable);
        if (result.ok) {
            // An undone dismissal lists the player again: their row comes back with Dismiss, not the question.
            s.confirming = false;
            emit('dismissed', player);
        } else {
            s.error = messageFor(result);
            s.confirming = false;
        }
    } finally {
        s.busy = false;
    }
}
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0 text-[13px]" data-testid="unmatched-drawer" aria-labelledby="unmatched-title">
        <div class="border-b border-border px-4 py-3">
            <h3 id="unmatched-title" class="flex items-center gap-2 text-sm font-semibold">
                <PhUserCircleDashed class="size-4 text-muted-foreground" aria-hidden="true" />Unmatched players
            </h3>
            <p class="mt-0.5 text-xs text-muted-foreground tabular-nums">
                <template v-if="players.length">{{ players.length }} seen on the game server</template>
                <template v-else>Everyone seen on the game server is linked or dismissed</template>{{ disabledReason ? `. ${disabledReason}` : '' }}
            </p>
        </div>

        <ul v-if="players.length" class="max-h-[70vh] divide-y divide-border overflow-y-auto">
            <li v-for="player in players" :key="keyOf(player)" class="grid gap-2 px-4 py-3" :data-testid="`unmatched-${player.platformId}`">
                <div class="min-w-0">
                    <p class="flex items-center gap-2">
                        <b class="truncate font-medium" data-testid="unmatched-name">{{ player.playerName }}</b>
                        <Badge variant="outline" class="shrink-0">{{ player.platformLabel }}</Badge>
                    </p>
                    <p class="truncate font-mono text-xs text-muted-foreground" data-testid="unmatched-id">{{ player.platformId }}</p>
                </div>

                <NativeSelect
                    v-model="of(player).trooperId"
                    :aria-label="`Trooper for ${player.playerName}`"
                    data-testid="unmatched-trooper"
                    :disabled="!editable || of(player).busy"
                >
                    <option value="">Choose a trooper</option>
                    <optgroup v-if="options[keyOf(player)].suggested.length" label="Closest by name">
                        <option v-for="t in options[keyOf(player)].suggested" :key="t.id" :value="String(t.id)">{{ fullName(t) }}</option>
                    </optgroup>
                    <optgroup v-if="options[keyOf(player)].suggested.length && options[keyOf(player)].rest.length" label="Everyone in this section">
                        <option v-for="t in options[keyOf(player)].rest" :key="t.id" :value="String(t.id)">{{ fullName(t) }}</option>
                    </optgroup>
                    <template v-else>
                        <option v-for="t in options[keyOf(player)].rest" :key="t.id" :value="String(t.id)">{{ fullName(t) }}</option>
                    </template>
                </NativeSelect>

                <p
                    v-if="of(player).error"
                    role="alert"
                    class="rounded-md bg-destructive/10 px-2.5 py-1.5 text-[13px] text-destructive"
                    data-testid="unmatched-error"
                >
                    {{ of(player).error }}
                </p>

                <div v-if="of(player).confirming" class="flex flex-wrap items-center gap-2 text-xs" data-testid="dismiss-confirm">
                    <span class="text-muted-foreground">Not a trooper? They stay off the lists until linked.</span>
                    <Button variant="destructive" size="xs" data-testid="confirm-dismiss" :disabled="of(player).busy" @click="dismiss(player)">Dismiss</Button>
                    <Button variant="outline" size="xs" :disabled="of(player).busy" @click="of(player).confirming = false">Keep</Button>
                </div>
                <div v-else class="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        data-testid="unmatched-link"
                        :disabled="!editable || of(player).busy || of(player).trooperId === ''"
                        :title="disabledReason ?? undefined"
                        @click="link(player)"
                    >
                        <PhCircleNotch v-if="of(player).busy" class="animate-spin" aria-hidden="true" />Link and mark present
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        data-testid="unmatched-dismiss"
                        :disabled="!editable || of(player).busy"
                        :title="disabledReason ?? undefined"
                        @click="of(player).confirming = true"
                    >
                        Dismiss
                    </Button>
                </div>
            </li>
        </ul>

        <!-- Dismissed for this practice (#176): closed by default, since a dismissal is usually right. -->
        <details v-if="dismissed.length" class="group border-t border-border" data-testid="dismissed-list">
            <summary
                class="flex cursor-pointer list-none items-center gap-1.5 px-4 py-2.5 text-xs font-medium text-muted-foreground hover:text-foreground tabular-nums [&::-webkit-details-marker]:hidden"
            >
                <PhCaretRight class="size-3 transition-transform group-open:rotate-90" aria-hidden="true" />Dismissed ({{ dismissed.length }})
            </summary>
            <ul class="max-h-[40vh] divide-y divide-border overflow-y-auto border-t border-border">
                <li v-for="player in dismissed" :key="keyOf(player)" class="flex items-center gap-2 px-4 py-2" :data-testid="`dismissed-${player.platformId}`">
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2">
                            <span class="truncate text-muted-foreground">{{ player.playerName }}</span>
                            <Badge variant="outline" class="shrink-0">{{ player.platformLabel }}</Badge>
                        </p>
                        <p class="truncate font-mono text-xs text-muted-foreground">{{ player.platformId }}</p>
                    </div>
                    <Button
                        variant="outline"
                        size="xs"
                        data-testid="undo-dismiss"
                        :disabled="!editable || !restore || restoring.has(keyOf(player))"
                        :title="disabledReason ?? undefined"
                        :aria-label="`Undo dismissing ${player.playerName}`"
                        @click="undoDismissal(player)"
                    >
                        <PhCircleNotch v-if="restoring.has(keyOf(player))" class="animate-spin" aria-hidden="true" />Undo
                    </Button>
                </li>
            </ul>
        </details>

        <AlertDialog :open="replacing !== null" @update:open="(open) => !open && cancelReplace()">
            <AlertDialogContent class="sm:max-w-md" data-testid="replace-dialog">
                <AlertDialogTitle class="text-base leading-snug font-semibold">
                    Replace {{ replacing ? trooperName(replacing.trooperId) : '' }}'s {{ replacing?.player.platformLabel }} ID?
                </AlertDialogTitle>
                <AlertDialogDescription v-if="replacing" class="text-[13px] text-muted-foreground" data-testid="replace-question">
                    {{ trooperName(replacing.trooperId) }} is linked to {{ replacing.current.playerName }} ({{ replacing.current.platformId }}) on
                    {{ replacing.player.platformLabel }}. Replace it with {{ replacing.player.playerName }} ({{ replacing.player.platformId }})?
                </AlertDialogDescription>
                <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                    <AlertDialogCancel class="mt-0" data-testid="replace-cancel">Cancel</AlertDialogCancel>
                    <Button data-testid="replace-confirm" @click="confirmReplace">Replace</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </Card>
</template>
