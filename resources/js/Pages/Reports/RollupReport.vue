<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    PhArrowDownRight,
    PhArrowSquareOut,
    PhArrowUpRight,
    PhCopy,
    PhLightning,
    PhLockSimple,
    PhSnowflake,
    PhWarning,
} from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import TimeAgo from '@/components/TimeAgo.vue';
import { weekPhrase } from '@/lib/forumDate';
import { dayTime, displayStatus, percent, points, TYPE_LABEL, unitLabel } from '@/lib/reportRollup';
import StatusPill from './StatusPill.vue';

// The selected unit's report in the roll-up workspace (report-p2.html's right panel). A sent
// report shows its frozen snapshot (AD-12); anything unsent is labelled live, so a reviewer never
// mistakes a moving draft for what was reported. Composing and sending live on `reports.show`.
const props = defineProps({
    node: { type: Object, required: true },
    byId: { type: Map, required: true },
    week: { type: Object, required: true },
    titleId: { type: String, default: 'report-title' },
});

const emit = defineEmits(['select', 'copy-reminder']);

const status = computed(() => displayStatus(props.node));
const leaf = computed(() => props.node.children.length === 0);
const missing = computed(() => (props.node.coverage?.missing ?? []).map((id) => props.byId.get(id)?.node).filter(Boolean));

// The status line around its time: `{ before, at, after }`; `at` is null when there is no time.
const meta = computed(() => {
    const node = props.node;
    if (node.status === 'sent') return { before: 'Frozen ', at: node.sentAt, after: node.sentBy ? ` by ${node.sentBy}` : '' };
    if (node.status === 'draft') return { before: 'Draft saved ', at: node.savedAt, after: ', not sent' };

    return { before: 'No report started yet', at: null, after: '' };
});

const liveNote = computed(() =>
    leaf.value
        ? 'Live draft, not sent. These figures follow attendance until the report is sent.'
        : 'Live draft, not sent. Figures roll up the sent reports below it only.',
);

const items = computed(() => {
    const { kpis, delta } = props.node;
    if (!kpis) return [];
    const rc = percent(kpis.rc);
    const sp = percent(kpis.sp);

    return [
        { label: 'In roll call', value: kpis.strength, change: delta?.strength, better: null },
        { label: 'RC % 30d', value: rc === null ? null : `${rc}%`, change: points(delta?.rcBp), unit: ' pts', better: 'up' },
        { label: 'SP % 30d', value: sp === null ? null : `${sp}%`, change: points(delta?.spBp), unit: ' pts', better: 'up' },
        { label: 'At-risk', value: kpis.atRisk, change: delta?.atRisk, better: 'down' },
        { label: 'On LOA', value: kpis.onLoa, change: delta?.onLoa, better: null },
        { label: 'Makeups', value: kpis.makeups, change: delta?.makeups, better: null },
    ];
});

function tone(item) {
    if (!item.change || item.better === null) return 'text-muted-foreground';

    return (item.change > 0) === (item.better === 'up') ? 'text-success' : 'text-destructive';
}

function ratioLine(child) {
    if (!child.kpis) return 'Unavailable';
    const rc = percent(child.kpis.rc);
    const sp = percent(child.kpis.sp);

    return rc === null && sp === null ? '' : `RC ${rc ?? 'n/a'}${rc === null ? '' : '%'}, SP ${sp ?? 'n/a'}${sp === null ? '' : '%'}`;
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" data-testid="report-panel">
        <div class="border-b border-border p-4 pr-12">
            <p class="text-xs text-muted-foreground">{{ TYPE_LABEL[node.type] }} report, {{ weekPhrase(week.start) }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <h2 :id="titleId" class="text-base leading-snug font-semibold">{{ unitLabel(node) }}</h2>
                <StatusPill :status="status" />
            </div>
            <p class="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                <PhLockSimple v-if="node.status === 'sent'" class="size-3.5" aria-hidden="true" />
                <span v-if="node.callsign" class="truncate">{{ node.name }}.</span>
                <span>{{ meta.before }}<TimeAgo v-if="meta.at" :at="meta.at">{{ dayTime(meta.at) }}</TimeAgo>{{ meta.after }}</span>
            </p>
        </div>

        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-4 text-[13px]">
            <p
                v-if="node.status === 'sent'"
                class="snap-strip flex items-center gap-2 rounded-md border border-border px-3 py-2 text-xs text-muted-foreground"
            >
                <PhSnowflake class="size-4 shrink-0" aria-hidden="true" />
                Frozen snapshot. Attendance edits after {{ dayTime(node.sentAt) }} do not change it.
            </p>
            <p
                v-else
                class="flex items-start gap-2 rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground"
                data-testid="live-label"
            >
                <PhLightning class="mt-px size-4 shrink-0" aria-hidden="true" />
                {{ liveNote }}
            </p>

            <div
                v-if="node.status !== 'sent' && missing.length"
                class="space-y-2 rounded-md border border-warning/40 bg-warning/6 p-3"
                data-testid="holding"
            >
                <p class="flex gap-2">
                    <PhWarning class="mt-px size-4 shrink-0 text-warning" aria-hidden="true" />
                    <span>
                        Covers <b class="tabular-nums">{{ node.coverage.sent }} of {{ node.coverage.of }}</b>
                        {{ node.coverage.of === 1 ? 'unit' : 'units' }} below it. Waiting on:
                    </span>
                </p>
                <ul class="space-y-1.5 pl-6">
                    <li v-for="child in missing" :key="child.id" class="flex flex-wrap items-center gap-2">
                        <button type="button" class="underline-offset-2 hover:underline" @click="emit('select', child.id)">
                            {{ unitLabel(child) }}
                        </button>
                        <StatusPill :status="displayStatus(child)" />
                        <Button variant="ghost" size="xs" class="ml-auto" @click="emit('copy-reminder', child)">
                            <PhCopy />Copy reminder
                        </Button>
                    </li>
                </ul>
            </div>

            <div>
                <div class="mb-2 flex items-baseline justify-between gap-2">
                    <h3 class="text-xs text-muted-foreground">Key figures</h3>
                    <span v-if="node.kpis" class="text-[11px] text-muted-foreground">
                        {{ node.delta ? "Compared with last week's sent report" : 'No sent report last week to compare' }}
                    </span>
                </div>
                <p
                    v-if="!node.kpis"
                    role="status"
                    class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/6 p-3"
                    data-testid="kpis-unavailable"
                >
                    <PhWarning class="mt-px size-4 shrink-0 text-warning" aria-hidden="true" />
                    {{
                        node.status === 'sent'
                            ? "This report's frozen snapshot cannot be read. An Admin needs to look at it."
                            : 'A sent report below this unit cannot be read, so its roll-up cannot be computed. An Admin needs to look at it.'
                    }}
                </p>
                <dl v-else class="grid grid-cols-3 gap-px overflow-hidden rounded-lg border border-border bg-border" data-testid="kpis">
                    <div v-for="item in items" :key="item.label" class="min-w-0 bg-card px-3 py-2.5">
                        <dt class="text-[11px] text-muted-foreground">{{ item.label }}</dt>
                        <dd class="mt-0.5 flex flex-wrap items-baseline gap-1.5">
                            <span class="text-lg font-semibold tracking-tight tabular-nums" :class="{ 'text-muted-foreground': node.live && leaf }">
                                {{ item.value ?? 'n/a' }}
                            </span>
                            <span
                                v-if="item.change !== undefined && item.change !== null"
                                class="inline-flex items-center gap-0.5 text-[11px] tabular-nums"
                                :class="tone(item)"
                            >
                                <PhArrowUpRight v-if="item.change > 0" class="size-3" aria-hidden="true" />
                                <PhArrowDownRight v-else-if="item.change < 0" class="size-3" aria-hidden="true" />
                                {{ item.change > 0 ? '+' : '' }}{{ item.change }}{{ item.change ? (item.unit ?? '') : '' }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-if="!leaf">
                <h3 class="mb-2 text-xs text-muted-foreground">Reports below it</h3>
                <ul class="rounded-md border border-border">
                    <li
                        v-for="child in node.children"
                        :key="child.id"
                        class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2 border-t border-border/70 px-2.5 py-1.5 first:border-t-0"
                    >
                        <button type="button" class="min-w-0 text-left underline-offset-2 hover:underline" @click="emit('select', child.id)">
                            <span class="block truncate">{{ unitLabel(child) }}</span>
                            <span v-if="child.callsign" class="block truncate text-xs text-muted-foreground">{{ child.name }}</span>
                        </button>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ ratioLine(child) }}</span>
                        <StatusPill :status="displayStatus(child)" />
                    </li>
                </ul>
            </div>

            <div>
                <h3 class="mb-2 text-xs text-muted-foreground">{{ node.status === 'sent' ? 'Leader notes' : 'Draft notes' }}</h3>
                <blockquote v-if="node.notes" class="rounded-md border border-border bg-muted/50 p-3 leading-relaxed whitespace-pre-line">
                    {{ node.notes }}
                </blockquote>
                <p v-else class="rounded-md border border-dashed border-border p-3 text-muted-foreground">No notes yet.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-border p-3">
            <Button as-child :variant="node.status === 'sent' ? 'outline' : 'default'">
                <Link :href="node.reportUrl" data-testid="open-report"><PhArrowSquareOut />Open report</Link>
            </Button>
            <Button v-if="node.status !== 'sent'" variant="outline" data-testid="copy-reminder" @click="emit('copy-reminder', node)">
                <PhCopy />Copy reminder
            </Button>
        </div>
    </div>
</template>

<style scoped>
.snap-strip {
    background-image: repeating-linear-gradient(-45deg, transparent 0 6px, color-mix(in oklab, var(--foreground) 3.5%, transparent) 6px 12px);
}
</style>
