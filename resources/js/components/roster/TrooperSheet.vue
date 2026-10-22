<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { PhArrowsClockwise, PhArrowSquareOut, PhClipboardText, PhEnvelopeSimple, PhIdentificationCard, PhLockSimple, PhSignOut, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import LoaSyncNote from '@/components/LoaSyncNote.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { forumDate } from '@/lib/forumDate';
import { makeupPmUrl } from '@/lib/makeupPm';
import { percentLabel, percentTone, RC_STATES, sourceLabel, SP_STATES, weekCell, weekLabel } from '@/lib/roster';
import RankBadge from './RankBadge.vue';
import WeekCell from './WeekCell.vue';

// The trooper Sheet both roster views open (roster-s2/s3 `openTrooper`): identity and billet, the
// 20-week heatmap, the last 8 weeks' marks with their source, and the way out to rollcall and
// milpac. It loads `roster.trooper`, which re-checks scope server-side for the trooper asked for.
const props = defineProps({
    trooperId: { type: Number, default: null },
    links: { type: Object, required: true },
    syncLabel: { type: String, required: true },
    // `LoaSyncState::toArray()`: "On LOA" and at-risk below move with leave, so its source and last sync show (AD-20).
    loaSync: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const state = ref('idle');
const data = ref(null);

async function load(id) {
    if (!id) return;
    state.value = 'loading';
    data.value = null;
    try {
        const response = await fetch(`/roster/troopers/${id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (id !== props.trooperId) return;
        if (!response.ok) {
            state.value = { 403: 'forbidden', 404: 'missing', 401: 'signedOut', 419: 'signedOut' }[response.status] ?? 'error';

            return;
        }
        data.value = await response.json();
        state.value = 'ready';
    } catch (error) {
        console.error(`Roster: loading trooper ${id} failed`, error);
        if (id === props.trooperId) state.value = 'error';
    }
}
watch(() => props.trooperId, load, { immediate: true });

const open = computed({
    get: () => props.trooperId !== null,
    set: (value) => value || emit('close'),
});

const trooper = computed(() => data.value?.trooper ?? null);
const weeks = computed(() => data.value?.weeks ?? []);
const current = computed(() => data.value?.currentWeek);

const cells = computed(() =>
    weeks.value.map((week, index) => ({
        week,
        index,
        mark: trooper.value.weeks[index],
        ...weekCell(trooper.value.weeks[index], week, { current: week === current.value, joined: trooper.value.joined }),
    })),
);
const recent = computed(() => cells.value.slice(-8).reverse());

// A trooper who missed a practice in the KPI window can be sent a makeup PM: a link that opens the forum's own
// "start conversation" form, pre-filled, for the leader to send there. None without a forum username.
const page = usePage();
const makeupHref = computed(() =>
    trooper.value?.missedWeeks?.length ? makeupPmUrl(page.props.forum?.baseUrl, trooper.value.name, trooper.value.missedWeeks) : null,
);

const FAILURES = {
    forbidden: ['This trooper is outside your scope.', 'You can only open troopers in units you command or were granted.'],
    missing: ['This trooper is not available.', 'They may have left their section since the page loaded, or sit outside the units you command or were granted. Reload to see the current roster.'],
    signedOut: ['Your session has expired.', 'Reload the page to sign in again.'],
    error: ['The trooper could not be loaded.', 'Try again in a moment.'],
};

const RC_TONE = { ok: 'success', cn: 'warning', bad: 'danger' };
const SP_TONE = { ok: 'success', mk: 'success', at: 'info', bad: 'danger' };
const toneClass = { bad: 'text-destructive', warn: 'text-warning', ok: '', muted: 'text-muted-foreground' };
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 bg-card p-0 sm:max-w-[30rem]" data-testid="trooper-sheet">
            <template v-if="state === 'ready' && trooper">
                <SheetHeader class="flex-row items-start gap-3 border-b border-border p-5 pr-12">
                    <RankBadge :rank="trooper.rank" :title="trooper.rankFull" large />
                    <div class="min-w-0">
                        <SheetDescription class="text-xs">
                            {{ trooper.billet ?? 'No billet' }}, {{ trooper.section.callsign ?? trooper.section.name }}
                        </SheetDescription>
                        <SheetTitle class="text-base leading-snug">{{ trooper.displayName }}</SheetTitle>
                    </div>
                </SheetHeader>

                <div class="flex-1 space-y-5 overflow-y-auto p-5 text-[13px]">
                    <InlineNotice v-if="trooper.departed" :icon="PhSignOut">
                        <p class="font-medium">Departed</p>
                        <p class="mt-0.5 text-muted-foreground">No longer on any Cav roster. Kept here with their history; not counted in strength.</p>
                    </InlineNotice>
                    <InlineNotice v-if="trooper.kpis?.onLoa" :icon="PhLockSimple">
                        <p class="font-medium">On LOA this week</p>
                        <p class="mt-0.5 text-muted-foreground">LOA weeks are left out of RC and SP, and never count as a miss.</p>
                    </InlineNotice>
                    <InlineNotice v-if="trooper.kpis?.atRisk" tone="warning" :icon="PhWarning" data-testid="sheet-at-risk">
                        <p class="font-medium">At-risk</p>
                        <p class="mt-0.5 text-muted-foreground">
                            Missed {{ trooper.kpis.missed }} section practices in the last 4 Cav weeks. A trooper is flagged at 2 missed
                            practices; LOA weeks never count.
                        </p>
                    </InlineNotice>

                    <dl v-if="trooper.kpis" class="grid grid-cols-3 gap-px overflow-hidden rounded-md border border-border bg-border">
                        <div class="bg-card p-3">
                            <dt class="text-xs text-muted-foreground">RC 30d</dt>
                            <dd class="text-lg font-semibold tabular-nums" :class="toneClass[percentTone(trooper.kpis.rc)]">{{ percentLabel(trooper.kpis.rc) }}</dd>
                            <dd class="text-[11px] text-muted-foreground tabular-nums">{{ trooper.kpis.rc[0] }} of {{ trooper.kpis.rc[1] }} signed</dd>
                        </div>
                        <div class="bg-card p-3">
                            <dt class="text-xs text-muted-foreground">SP 30d</dt>
                            <dd class="text-lg font-semibold tabular-nums" :class="toneClass[percentTone(trooper.kpis.sp)]">{{ percentLabel(trooper.kpis.sp) }}</dd>
                            <dd class="text-[11px] text-muted-foreground tabular-nums">{{ trooper.kpis.sp[0] }} of {{ trooper.kpis.sp[1] }} attended</dd>
                        </div>
                        <div class="bg-card p-3">
                            <dt class="text-xs text-muted-foreground">Missed, 30d</dt>
                            <dd class="text-lg font-semibold tabular-nums" :class="trooper.kpis.atRisk && 'text-warning'">{{ trooper.kpis.missed }}</dd>
                            <dd class="text-[11px] text-muted-foreground">section practices</dd>
                        </div>
                    </dl>
                    <LoaSyncNote v-if="trooper.kpis && loaSync" :sync="loaSync" class="-mt-3" />

                    <div>
                        <h3 class="mb-2 text-xs text-muted-foreground">20 Cav weeks</h3>
                        <div class="flex gap-[3px] overflow-x-auto pb-1" role="img" :aria-label="`Attendance over the last 20 Cav weeks, ${weekLabel(weeks[0])} to this week`">
                            <span
                                v-for="cell in cells"
                                :key="cell.week"
                                :title="`Week of ${weekLabel(cell.week)}. Rollcall: ${RC_STATES[cell.rc]}. SP: ${SP_STATES[cell.sp]}.`"
                                :class="cell.week === current && 'rounded-[4px] outline outline-[1.5px] outline-offset-2 outline-primary'"
                            >
                                <WeekCell :rc="cell.rc" :sp="cell.sp" size="xs" />
                            </span>
                        </div>
                        <div class="mt-1 flex justify-between text-[11px] text-muted-foreground"><span>{{ weekLabel(weeks[0]) }}</span><span>This week</span></div>
                    </div>

                    <dl class="grid grid-cols-[7.5rem_1fr] gap-y-2">
                        <dt class="text-muted-foreground">Rank</dt>
                        <dd>{{ trooper.rankFull ?? trooper.rank }}</dd>
                        <dt class="text-muted-foreground">Billet</dt>
                        <dd>{{ trooper.billet ?? 'None reported' }}</dd>
                        <dt class="text-muted-foreground">MOS</dt>
                        <dd>{{ trooper.mos ?? 'None reported' }}</dd>
                        <dt class="text-muted-foreground">Section</dt>
                        <dd>
                            {{ trooper.section.callsign ?? trooper.section.name }}<template v-if="trooper.sectionLeader && trooper.role !== 'SL'">, led by {{ trooper.sectionLeader.displayName }}</template>
                            <template v-else-if="trooper.role === 'SL'">, leads this section</template>
                        </dd>
                        <dt class="text-muted-foreground">Joined 7Cav</dt>
                        <dd class="tabular-nums">{{ trooper.joined ? forumDate(trooper.joined) : 'Unknown' }}</dd>
                        <dt class="text-muted-foreground">Time in grade</dt>
                        <dd class="tabular-nums">{{ trooper.tig ? `Since ${forumDate(trooper.tig)}` : 'Unknown' }}</dd>
                        <dt class="text-muted-foreground">Milpac ID</dt>
                        <dd class="tabular-nums">#{{ trooper.cavId }}</dd>
                        <dt class="text-muted-foreground">Source</dt>
                        <dd class="inline-flex items-center gap-1 text-muted-foreground"><PhArrowsClockwise aria-hidden="true" />{{ syncLabel }}</dd>
                    </dl>

                    <div>
                        <h3 class="mb-1 text-xs text-muted-foreground">Last 8 weeks, newest first</h3>
                        <table class="w-full text-left" data-testid="sheet-recent">
                            <thead class="sr-only">
                                <tr><th>Week</th><th>Rollcall</th><th>Section practice</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="cell in recent" :key="cell.week" class="border-b border-border align-top last:border-0">
                                    <td class="py-2 pr-3 whitespace-nowrap">
                                        <span class="font-medium tabular-nums">{{ weekLabel(cell.week) }}</span>
                                        <span v-if="cell.week === current" class="block text-[11px] text-muted-foreground">This week</span>
                                    </td>
                                    <td class="py-2 pr-3"><Badge :variant="RC_TONE[cell.rc] ?? 'outline'">{{ RC_STATES[cell.rc] }}</Badge></td>
                                    <td class="py-2">
                                        <Badge :variant="SP_TONE[cell.sp] ?? 'outline'">{{ SP_STATES[cell.sp] }}</Badge>
                                        <span v-if="sourceLabel(cell.mark)" class="mt-1 block text-[11px] text-muted-foreground">{{ sourceLabel(cell.mark) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <SheetFooter class="flex-row flex-wrap items-center gap-2 border-t border-border p-4">
                    <Button v-if="links.rollcall" as-child>
                        <Link :href="`${links.rollcall}${trooper.section.id}`" data-testid="sheet-open-rollcall"><PhClipboardText />Open rollcall</Link>
                    </Button>
                    <Button v-if="links.trooper" as-child :variant="links.rollcall ? 'outline' : 'default'">
                        <Link :href="`${links.trooper}${trooperId}`" data-testid="sheet-open-trooper"><PhIdentificationCard />Open milpac</Link>
                    </Button>
                    <Button as-child variant="outline">
                        <a :href="trooper.milpacUrl" target="_blank" rel="noopener noreferrer" data-testid="sheet-milpac"><PhArrowSquareOut />View on 7cav.us</a>
                    </Button>
                    <Button v-if="makeupHref" as-child variant="outline">
                        <a :href="makeupHref" target="_blank" rel="noopener noreferrer" data-testid="sheet-makeup-pm">
                            <PhEnvelopeSimple />Send makeup PM<span class="sr-only"> (opens the forum in a new tab)</span>
                        </a>
                    </Button>
                </SheetFooter>
            </template>

            <template v-else>
                <SheetHeader class="border-b border-border p-5 pr-12">
                    <SheetTitle class="text-base">Trooper details</SheetTitle>
                    <SheetDescription class="sr-only">Identity, attendance and links for the selected trooper.</SheetDescription>
                </SheetHeader>
                <div v-if="state === 'loading'" class="space-y-4 p-5" aria-busy="true" aria-label="Loading trooper">
                    <Skeleton class="h-16 w-full" />
                    <Skeleton class="h-6 w-3/4" />
                    <Skeleton class="h-40 w-full" />
                </div>
                <div v-else class="space-y-3 p-5 text-[13px]" role="alert">
                    <p class="font-medium">{{ FAILURES[state]?.[0] ?? FAILURES.error[0] }}</p>
                    <p class="text-muted-foreground">{{ FAILURES[state]?.[1] ?? FAILURES.error[1] }}</p>
                    <Button v-if="state === 'error'" variant="outline" size="sm" @click="load(trooperId)">Try again</Button>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
