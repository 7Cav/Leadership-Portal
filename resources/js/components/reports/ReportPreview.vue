<script setup>
import { computed } from 'vue';
import { PhArrowsClockwise, PhCopy, PhEye, PhLightning, PhLockSimple, PhPencilSimple, PhStack, PhWarningCircle } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { copyText } from '@/lib/clipboard';
import LoaSyncNote from '@/components/LoaSyncNote.vue';
import TimeAgo from '@/components/TimeAgo.vue';
import { forumDate } from '@/lib/forumDate';
import { baselineText, formatKpi, KPIS, stamp, timeOf, unitLabel } from '@/lib/report';
import { reportSummary } from '@/lib/reportSummary';
import HighlightList from './HighlightList.vue';
import KpiGrid from './KpiGrid.vue';
import TrendChart from './TrendChart.vue';

// The right-hand preview (P3): the report as command will read it. A draft is live and says so;
// a sent report is the frozen snapshot and says when it froze (AD-12, AD-20).
const props = defineProps({
    unit: { type: Object, required: true },
    week: { type: Object, required: true },
    sent: { type: Boolean, default: false },
    sentAt: { type: String, default: null },
    sentBy: { type: String, default: null },
    kpis: { type: Object, default: null },
    kpiError: { type: String, default: null },
    delta: { type: Object, default: null },
    baseline: { type: Object, required: true },
    trend: { type: Array, required: true },
    highlights: { type: Array, required: true },
    troopers: { type: Array, default: null },
    children: { type: Array, default: null },
    missingChildren: { type: Array, default: () => [] },
    content: { type: Object, required: true },
    reasonLabels: { type: Object, required: true },
    names: { type: Object, default: () => ({}) },
    rosterSync: { type: Object, required: true },
    // A section's live draft: on LOA and at-risk move with leave, so its source and last sync show (AD-20).
    loaSync: { type: Object, default: null },
    computedAt: { type: String, required: true },
});

const audience = computed(() => (props.unit.parent ? `${unitLabel(props.unit.parent)} and command` : 'command'));
const reasonFor = computed(() => new Map((props.content.unaccounted ?? []).map((r) => [r.trooper_id, r.reason])));

const hints = computed(() => {
    if (!props.kpis) return {};
    const rows = props.troopers ?? [];
    const names = (filter) => rows.filter(filter).map((t) => t.name).join(', ');

    return {
        // The headcount sits beside the rollcall figure, labelled apart (#424); a report frozen before it has none.
        strength: [props.kpis.headcount != null ? `Headcount ${props.kpis.headcount}, with staff` : null, props.unit.isLeaf ? 'Assigned troopers' : 'From sent reports only'].filter(Boolean).join(' · '),
        rc: 'Signed, last 30 days',
        sp: 'Credited, last 30 days',
        atRisk: names((t) => t.atRisk) || '',
        onLoa: names((t) => t.onLoa) || '',
        makeups: names((t) => t.status === 'makeup') || '',
    };
});

const RC = {
    will_attend: ['Will attend', 'success'],
    cannot_attend: ['Cannot attend', 'outline'],
    unsigned: ['Unsigned', 'warning'],
};
const SP = {
    present: ['Present', 'success'],
    missed: ['Missed', 'danger'],
    makeup: ['Makeup', 'info'],
    attached: ['Attached', 'info'],
    loa: ['LOA', 'outline'],
};

function noteFor(trooper) {
    const parts = [];
    const reason = reasonFor.value.get(trooper.id);
    if (reason) parts.push(props.reasonLabels[reason]);
    if (trooper.atRisk) parts.push(`At-risk, ${trooper.missed} misses in 30 days`);
    if (trooper.source === 'auto') parts.push('Marked from game server capture');

    return parts.join('. ');
}

// A few plain lines for a Discord or staff thread: the draft as it stands, or the sent snapshot.
function copySummary() {
    copyText(
        reportSummary({
            unit: props.unit,
            week: props.week,
            sent: props.sent,
            sentAt: props.sentAt,
            sentBy: props.sentBy,
            kpis: props.kpis,
            delta: props.delta,
            trainingSubject: props.content.trainingSubject,
            notes: props.content.notes,
        }),
        'Summary copied',
        { description: props.sent ? 'Paste it into Discord or a staff thread.' : 'This is the draft as it stands, not a sent report.', failed: 'Could not copy the summary' },
    );
}

const childLabel = (id) => unitLabel((props.children ?? []).find((c) => c.id === id)) || 'A unit no longer below this one';
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-border bg-card shadow-[var(--shadow-panel)]" aria-labelledby="preview-title" tabindex="-1" id="preview">
        <div class="flex items-start gap-3 px-5 pt-4 pb-3.5">
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><PhEye aria-hidden="true" />Preview, as {{ audience }} see it</p>
                <h2 id="preview-title" class="mt-1 text-base font-semibold">{{ unitLabel(unit) }}, week of {{ forumDate(week.start) }}</h2>
                <p v-if="unit.isLeaf" class="mt-0.5 text-[13px]">
                    <template v-if="content.trainingSubject?.trim()">Trained: {{ content.trainingSubject.trim() }}</template>
                    <span v-else class="text-muted-foreground italic">Training subject missing</span>
                </p>
                <p v-else class="mt-0.5 text-[13px] text-muted-foreground">Rolled up from sent reports only</p>
            </div>
            <div class="flex flex-none flex-col items-end gap-1.5">
                <Badge v-if="sent" variant="success"><PhLockSimple aria-hidden="true" />Sent {{ timeOf(sentAt) }}</Badge>
                <Badge v-else variant="warning"><PhPencilSimple aria-hidden="true" />Draft, live</Badge>
                <Button variant="ghost" size="xs" data-testid="copy-summary" @click="copySummary"><PhCopy aria-hidden="true" />Copy summary</Button>
            </div>
        </div>

        <div v-if="kpiError" role="alert" class="flex items-start gap-2.5 border-t border-border px-5 py-4 text-[13px]">
            <PhWarningCircle class="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
            <p>{{ kpiError }}</p>
        </div>

        <template v-else-if="kpis">
            <div class="border-t border-border"><KpiGrid :kpis="kpis" :delta="delta" :hints="hints" /></div>
            <p class="px-5 pt-2 text-[11px] text-muted-foreground" data-testid="baseline">{{ baselineText(baseline) }}</p>
            <LoaSyncNote v-if="loaSync" :sync="loaSync" class="px-5 pt-1" />

            <div class="border-t border-border px-5 py-4 mt-2">
                <TrendChart :points="trend" :live="!sent" />
            </div>

            <div class="border-t border-border px-5 py-4">
                <div class="mb-1.5 flex flex-wrap items-center gap-2">
                    <h3 class="text-[13px] font-semibold">Highlights</h3>
                    <span class="text-xs text-muted-foreground">
                        {{ sent ? 'Written from the frozen snapshot' : "Written from this week's data, updates as you work" }}
                    </span>
                </div>
                <HighlightList :items="highlights" />
            </div>

            <div v-if="troopers" class="border-t border-border px-5 py-4">
                <h3 class="mb-1 text-[13px] font-semibold">Troopers</h3>
                <EmptyState v-if="!troopers.length" :icon="PhStack" title="No troopers in this section" class="py-6">
                    Troopers appear here once the roster places them in this section.
                </EmptyState>
                <div v-else class="overflow-x-auto">
                    <Table class="text-[13px]" data-testid="trooper-table">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-auto py-2 text-xs">Trooper</TableHead>
                                <TableHead class="h-auto py-2 text-xs">Roll call</TableHead>
                                <TableHead class="h-auto py-2 text-xs">SP</TableHead>
                                <TableHead class="h-auto py-2 text-xs">Note</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="t in troopers" :key="t.id">
                                <TableCell class="font-medium">{{ t.name }}</TableCell>
                                <TableCell>
                                    <Badge v-if="t.onLoa && t.rollcall === 'unsigned'" variant="outline">LOA</Badge>
                                    <Badge v-else :variant="RC[t.rollcall][1]">{{ RC[t.rollcall][0] }}</Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge v-if="t.status" :variant="SP[t.status][1]">{{ SP[t.status][0] }}</Badge>
                                    <Badge v-else variant="warning">Not marked</Badge>
                                </TableCell>
                                <TableCell class="min-w-[12rem] text-xs whitespace-normal text-muted-foreground">{{ noteFor(t) }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </div>

            <div v-if="sent && unit.isLeaf && content.unaccounted?.length" class="border-t border-border px-5 py-4">
                <h3 class="mb-1.5 text-[13px] font-semibold">Unaccounted troopers</h3>
                <ul class="space-y-1 text-[13px]">
                    <li v-for="row in content.unaccounted" :key="row.trooper_id">
                        <span class="font-medium">{{ names[row.trooper_id] ?? 'A trooper no longer in the section' }}</span>
                        <span class="text-muted-foreground">, {{ reasonLabels[row.reason] }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="children" class="border-t border-border px-5 py-4">
                <h3 class="mb-1 text-[13px] font-semibold">Units</h3>
                <div class="overflow-x-auto">
                    <Table class="text-[13px]" data-testid="children-table">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-auto py-2 text-xs">Unit</TableHead>
                                <TableHead class="h-auto py-2 text-xs">Status</TableHead>
                                <TableHead v-for="k in sent ? [] : KPIS.slice(0, 4)" :key="k.key" class="h-auto py-2 text-right text-xs">{{ k.label.replace(' 30d', '') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="c in children" :key="c.id" :class="{ 'text-muted-foreground': !['sent', 'included'].includes(c.status) }">
                                <TableCell class="font-medium">{{ unitLabel(c) }}</TableCell>
                                <TableCell>
                                    <Badge v-if="c.status === 'sent'" variant="success">Sent</Badge>
                                    <Badge v-else-if="c.status === 'included'" variant="success">In this report</Badge>
                                    <Badge v-else-if="c.status === 'missing'" variant="outline">Not in at send</Badge>
                                    <Badge v-else variant="outline">Not in</Badge>
                                </TableCell>
                                <TableCell v-for="k in sent ? [] : KPIS.slice(0, 4)" :key="k.key" class="text-right tabular-nums">
                                    {{ c.kpis ? formatKpi(c.kpis, k) : '—' }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <p v-if="sent && missingChildren.length" class="mt-2 text-xs text-muted-foreground">
                    Sent without {{ missingChildren.map(childLabel).join(', ') }}.
                </p>
            </div>
        </template>

        <div v-if="content.notes?.trim() || sent" class="border-t border-border px-5 py-4">
            <h3 class="mb-2 text-[13px] font-semibold">Leader notes</h3>
            <p class="text-[13px] leading-relaxed whitespace-pre-line" :class="{ 'text-muted-foreground italic': !content.notes?.trim() }">
                {{ content.notes?.trim() || 'Nothing written' }}
            </p>
        </div>

        <p class="flex flex-wrap gap-x-4 gap-y-1 border-t border-border bg-muted/45 px-5 py-3 text-[11px] text-muted-foreground" data-testid="provenance">
            <span v-if="unit.isLeaf" class="inline-flex items-center gap-1.5">
                <PhArrowsClockwise aria-hidden="true" />
                <span v-if="rosterSync.at">Roster from {{ rosterSync.source }}, synced <TimeAgo :at="rosterSync.at">{{ stamp(rosterSync.at) }}</TimeAgo></span>
                <template v-else>Roster from {{ rosterSync.source }}, not synced yet</template>
            </span>
            <span v-if="sent" class="inline-flex items-center gap-1.5"><PhLockSimple aria-hidden="true" /><span>Frozen <TimeAgo :at="sentAt">{{ stamp(sentAt) }}</TimeAgo></span></span>
            <span v-else-if="unit.isLeaf" class="inline-flex items-center gap-1.5">
                <PhLightning aria-hidden="true" /><span>Computed from roll call and attendance at <TimeAgo :at="computedAt">{{ timeOf(computedAt) }}</TimeAgo>. Marks are manual unless noted as game server capture.</span>
            </span>
            <span v-else class="inline-flex items-center gap-1.5"><PhStack aria-hidden="true" />Built from the frozen reports of the units below</span>
        </p>
    </section>
</template>
