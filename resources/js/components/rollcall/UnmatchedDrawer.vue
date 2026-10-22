<script setup>
import { computed, reactive } from 'vue';
import { PhCircleNotch, PhUserCircleDashed } from '@phosphor-icons/vue';
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
// work (an audited link, then the manual-wins pre-mark) and scope-gates both actions (AD-8).
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

async function link(player) {
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
        const result = await postJson(`/rollcall/${props.sectionId}/unmatched/link`, { ...body(player), trooper_id: trooperId }).catch(unreachable);
        if (result.ok) emit('linked', { player, ...result.data });
        else s.error = messageFor(result);
    } finally {
        s.busy = false;
    }
}

async function dismiss(player) {
    const s = of(player);
    if (!props.editable || s.busy) return;

    s.busy = true;
    s.error = null;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/unmatched/dismiss`, body(player)).catch(unreachable);
        if (result.ok) emit('dismissed', player);
        else {
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
                <PhUserCircleDashed class="size-4 text-muted-foreground" aria-hidden="true" />Not matched to a trooper
            </h3>
            <p class="mt-0.5 text-xs text-muted-foreground tabular-nums">
                {{ players.length }} seen on the server{{ disabledReason ? `. ${disabledReason}` : '' }}
            </p>
        </div>

        <ul class="max-h-[70vh] divide-y divide-border overflow-y-auto">
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
                    <option value="">Choose trooper</option>
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
                    <span class="text-muted-foreground">Not a member? They stay off the lists until linked.</span>
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
    </Card>
</template>
