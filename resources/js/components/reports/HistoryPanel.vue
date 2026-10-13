<script setup>
import { computed, ref } from 'vue';
import { PhClockCounterClockwise, PhFileText, PhGitDiff, PhLockSimple } from '@phosphor-icons/vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { forumDate } from '@/lib/forumDate';
import { KPIS, formatKpi, kpiValue, percent, sentenceDiff, stamp } from '@/lib/report';
import DeltaBadge from './DeltaBadge.vue';
import KpiGrid from './KpiGrid.vue';

// History (P3): the unit's sent reports, newest first, each exactly as frozen. "Changes" compares
// one with the sent report before it — numbers, subject and notes; "Full snapshot" shows it whole.
const props = defineProps({
    entries: { type: Array, required: true },
    reasonLabels: { type: Object, required: true },
    names: { type: Object, default: () => ({}) },
    isLeaf: { type: Boolean, default: true },
});

const selected = ref(0);
const mode = ref('diff');
const current = computed(() => props.entries[selected.value]);
const previous = computed(() => props.entries[selected.value + 1] ?? null);

// Like for like: the change between the two frozen snapshots, never a recompute.
function change(kpi) {
    const a = kpiValue(previous.value?.kpis, kpi);
    const b = kpiValue(current.value?.kpis, kpi);
    if (a === null || b === null || a === undefined || b === undefined) return null;
    const value = b - a;

    return {
        value,
        text: `${value > 0 ? '+' : ''}${value}`,
        direction: value === 0 ? 'flat' : value > 0 ? 'up' : 'down',
        tone: value === 0 ? 'neutral' : (value > 0) === (kpi.polarity > 0) ? 'good' : 'bad',
        unit: kpi.rate ? 'points' : '',
    };
}

function onKey(event, index) {
    const next = index + (event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0);
    if (next === index || next < 0 || next >= props.entries.length) return;
    event.preventDefault();
    selected.value = next;
    event.currentTarget.parentElement.children[next]?.focus();
}

const DIFF = { added: 'text-foreground', removed: 'text-muted-foreground line-through', same: 'text-muted-foreground' };
</script>

<template>
    <EmptyState v-if="!entries.length" :icon="PhClockCounterClockwise" title="No sent reports yet" class="rounded-lg border border-border bg-card py-14">
        The first report shows up here after it is frozen and sent. Each one is kept exactly as sent.
    </EmptyState>

    <div v-else class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
        <section class="rounded-lg border border-border bg-card" aria-labelledby="history-list-title">
            <div class="flex items-center gap-2 px-4 pt-3.5 pb-2">
                <h2 id="history-list-title" class="text-[15px] font-semibold">Sent reports</h2>
                <span class="ml-auto text-xs text-muted-foreground">Newest first</span>
            </div>
            <div class="p-1.5 pt-0" role="listbox" aria-label="Sent reports" data-testid="history-list">
                <button
                    v-for="(entry, index) in entries"
                    :key="entry.week"
                    type="button"
                    role="option"
                    :aria-selected="index === selected"
                    :tabindex="index === selected ? 0 : -1"
                    class="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-0.5 rounded-md px-3 py-2.5 text-left hover:bg-accent/60 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none aria-selected:bg-accent aria-selected:shadow-[inset_2px_0_0_var(--primary)]"
                    @click="selected = index"
                    @keydown="onKey($event, index)"
                >
                    <span class="text-[13px] font-medium">Week of {{ forumDate(entry.week) }}</span>
                    <span class="justify-self-end text-xs text-muted-foreground tabular-nums">
                        RC {{ percent(entry.kpis?.rc) ?? '—' }} SP {{ percent(entry.kpis?.sp) ?? '—' }}
                    </span>
                    <span class="col-span-2 truncate text-xs text-muted-foreground">Sent {{ stamp(entry.sentAt) }}</span>
                </button>
            </div>
        </section>

        <section class="overflow-hidden rounded-lg border border-border bg-card" aria-labelledby="history-title">
            <div class="flex flex-wrap items-start gap-3 px-5 pt-4 pb-3.5">
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><PhLockSimple aria-hidden="true" />Frozen snapshot, read-only</p>
                    <h2 id="history-title" class="mt-1 text-base font-semibold">Week of {{ forumDate(current.week) }}</h2>
                    <p class="mt-0.5 text-[13px] text-muted-foreground">
                        Sent {{ stamp(current.sentAt) }}<template v-if="current.sentBy"> by {{ current.sentBy }}</template>.
                        <template v-if="current.trainingSubject"> Trained: {{ current.trainingSubject }}.</template>
                    </p>
                </div>
                <div class="inline-flex gap-0.5 rounded-md bg-muted p-0.5" role="group" aria-label="History view">
                    <button
                        v-for="[value, label, icon] in [['diff', previous ? `Changes vs ${forumDate(previous.week)}` : 'Changes', PhGitDiff], ['full', 'Full snapshot', PhFileText]]"
                        :key="value"
                        type="button"
                        :aria-pressed="mode === value"
                        class="inline-flex h-[1.625rem] items-center gap-1.5 rounded-sm px-2.5 text-xs font-medium text-muted-foreground aria-pressed:bg-card aria-pressed:text-foreground aria-pressed:shadow-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        @click="mode = value"
                    >
                        <component :is="icon" aria-hidden="true" />{{ label }}
                    </button>
                </div>
            </div>

            <div v-if="!current.kpis" class="border-t border-border px-5 py-4 text-[13px] text-muted-foreground">
                This snapshot cannot be read.
            </div>

            <template v-else-if="mode === 'diff'">
                <p v-if="!previous" class="border-t border-border px-5 py-4 text-[13px] text-muted-foreground">
                    No earlier sent report to compare with.
                </p>
                <template v-else>
                    <div class="border-t border-border px-5 py-4">
                        <h3 class="mb-1 text-[13px] font-semibold">KPIs</h3>
                        <div class="overflow-x-auto">
                            <Table class="text-[13px]" data-testid="history-diff">
                                <TableHeader>
                                    <TableRow class="hover:bg-transparent">
                                        <TableHead class="h-auto py-2 text-xs">Metric</TableHead>
                                        <TableHead class="h-auto py-2 text-right text-xs">{{ forumDate(previous.week) }}</TableHead>
                                        <TableHead class="h-auto py-2 text-right text-xs">{{ forumDate(current.week) }}</TableHead>
                                        <TableHead class="h-auto py-2 text-right text-xs">Change</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="kpi in KPIS" :key="kpi.key">
                                        <TableCell>{{ kpi.label }}</TableCell>
                                        <TableCell class="text-right text-muted-foreground tabular-nums">{{ formatKpi(previous.kpis, kpi) }}</TableCell>
                                        <TableCell class="text-right font-medium tabular-nums">{{ formatKpi(current.kpis, kpi) }}</TableCell>
                                        <TableCell class="text-right"><DeltaBadge :change="change(kpi)" /></TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                    <div class="space-y-3 border-t border-border px-5 py-4">
                        <h3 class="text-[13px] font-semibold">Notes</h3>
                        <div v-for="[label, key] in (isLeaf ? [['Training subject', 'trainingSubject'], ['Leader notes', 'notes']] : [['Leader notes', 'notes']])" :key="key">
                            <h4 class="mb-1 text-xs text-muted-foreground">{{ label }}</h4>
                            <ul class="space-y-0.5 text-[13px]">
                                <li v-for="(line, i) in sentenceDiff(previous[key], current[key])" :key="i" class="flex gap-2" :class="DIFF[line.kind]">
                                    <span aria-hidden="true" class="w-3 flex-none" :class="{ 'text-success': line.kind === 'added' }">{{ line.kind === 'added' ? '+' : line.kind === 'removed' ? '-' : '' }}</span>
                                    <span><span class="sr-only">{{ line.kind === 'added' ? 'Added: ' : line.kind === 'removed' ? 'Removed: ' : '' }}</span>{{ line.text }}</span>
                                </li>
                                <li v-if="!sentenceDiff(previous[key], current[key]).length" class="text-muted-foreground italic">Nothing written</li>
                            </ul>
                        </div>
                    </div>
                </template>
            </template>

            <template v-else>
                <div class="border-t border-border"><KpiGrid :kpis="current.kpis" :delta="current.delta" /></div>
                <div v-if="current.unaccounted.length" class="border-t border-border px-5 py-4">
                    <h3 class="mb-1.5 text-[13px] font-semibold">Unaccounted troopers</h3>
                    <ul class="space-y-1 text-[13px]">
                        <li v-for="row in current.unaccounted" :key="row.trooper_id">
                            <span class="font-medium">{{ names[row.trooper_id] ?? 'A trooper no longer in the section' }}</span>
                            <span class="text-muted-foreground">, {{ reasonLabels[row.reason] }}</span>
                        </li>
                    </ul>
                </div>
                <div class="border-t border-border px-5 py-4">
                    <h3 class="mb-2 text-[13px] font-semibold">Leader notes</h3>
                    <p class="text-[13px] leading-relaxed whitespace-pre-line" :class="{ 'text-muted-foreground italic': !current.notes }">
                        {{ current.notes || 'Nothing written' }}
                    </p>
                </div>
            </template>
        </section>
    </div>
</template>
