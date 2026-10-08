<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { PhCaretDown, PhCaretLeft, PhCaretRight, PhChecks, PhLightning, PhTreeStructure } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import EmptyState from '@/components/EmptyState.vue';
import { forumDate } from '@/lib/forumDate';
import {
    countNodes,
    dayTime,
    defaultExpanded,
    displayStatus,
    indexTree,
    percent,
    progress as progressOf,
    reminderText,
    unitLabel,
    visibleRows,
} from '@/lib/reportRollup';
import RollupReport from './RollupReport.vue';
import StatusPill from './StatusPill.vue';

// The report roll-up workspace (Story 5.8, binding design mockups/v2/report-p2.html): the chain of
// the viewer's scope as an accordion treegrid with each unit's KPIs and send status, and the
// selected unit's report beside it (docked from 1280px, a Sheet below). Read-only: every unit
// links to its own report screen (`reports.show`) for composing and sending.
const props = defineProps({
    // `{ start, reportDue, isCurrent, options: ['YYYY-MM-DD', ...] }`, newest first.
    week: { type: Object, required: true },
    // The unit in the URL (`/reports/{unit}`), or null for the viewer's whole scope.
    scopeUnitId: { type: Number, default: null },
    // The chain, one tree per top unit of the scope (ReportRollupController::present).
    roots: { type: Array, required: true },
});

const byId = computed(() => indexTree(props.roots));
const expanded = ref(defaultExpanded(props.roots));
const filter = ref(countNodes(props.roots, (node) => node.open) > 0 ? 'open' : 'all');
const selectedId = ref(props.roots[0]?.id ?? null);
const focusedId = ref(selectedId.value);
const sheetOpen = ref(false);
const docked = useMediaQuery('(min-width: 1280px)');
const treeEl = ref(null);

const rows = computed(() => visibleRows(props.roots, { expanded: expanded.value, filter: filter.value }));
const selected = computed(() => byId.value.get(selectedId.value)?.node ?? null);
const progress = computed(() => progressOf(props.roots));
const openCount = computed(() => countNodes(props.roots, (node) => node.status !== 'sent'));
const allCount = computed(() => countNodes(props.roots));

const scopeName = computed(() => (props.roots.length === 1 ? unitLabel(props.roots[0]) : null));
const weekKey = computed(() => props.week.start.slice(0, 10));
const weekIndex = computed(() => props.week.options.indexOf(weekKey.value));

// A new visit (another week or unit) brings a new chain: reset the view onto it.
watch(
    () => props.roots,
    (roots) => {
        expanded.value = defaultExpanded(roots);
        if (!byId.value.has(selectedId.value)) selectedId.value = roots[0]?.id ?? null;
        focusedId.value = selectedId.value;
    },
);

// Keep the roving tab stop on a row that is on screen.
watch(rows, (list) => {
    if (!list.some((row) => row.node.id === focusedId.value)) focusedId.value = list[0]?.node.id ?? null;
});

function goToWeek(date) {
    const path = props.scopeUnitId === null ? '/reports' : `/reports/${props.scopeUnitId}`;
    router.get(path, date === props.week.options[0] ? {} : { week: date }, { preserveScroll: true });
}

// ── Countdown to the deadline, for the running week only ──
const now = ref(Date.now());
let timer = null;
onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 30_000);
});
onBeforeUnmount(() => clearInterval(timer));

const countdown = computed(() => {
    let seconds = Math.floor((new Date(props.week.reportDue).getTime() - now.value) / 1000);
    if (seconds <= 0) return 'Deadline passed';
    const d = Math.floor(seconds / 86400);
    seconds -= d * 86400;
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds - h * 3600) / 60);

    return `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m left`;
});

// ── Selection and the treegrid's keyboard model (WAI-ARIA treegrid, row focus) ──
function select(id, { openSheet = true } = {}) {
    selectedId.value = id;
    focusedId.value = id;
    revealAncestors(id);
    if (openSheet && !docked.value) sheetOpen.value = true;
}

function revealAncestors(id) {
    let parentId = byId.value.get(id)?.parentId ?? null;
    const next = new Set(expanded.value);
    while (parentId !== null) {
        next.add(parentId);
        parentId = byId.value.get(parentId)?.parentId ?? null;
    }
    expanded.value = next;
    if (filter.value === 'open' && !byId.value.get(id)?.node.open) filter.value = 'all';
}

function toggle(id, open = !expanded.value.has(id)) {
    const next = new Set(expanded.value);
    if (open) next.add(id);
    else next.delete(id);
    expanded.value = next;
}

async function focusRow(id) {
    focusedId.value = id;
    await nextTick();
    treeEl.value?.querySelector(`[data-row-id="${id}"]`)?.focus();
}

function onKeydown(event, row) {
    const list = rows.value;
    const index = list.findIndex((candidate) => candidate.node.id === row.node.id);
    const move = (target) => target && focusRow(target.node.id);

    switch (event.key) {
        case 'ArrowDown':
            move(list[index + 1]);
            break;
        case 'ArrowUp':
            move(list[index - 1]);
            break;
        case 'Home':
            move(list[0]);
            break;
        case 'End':
            move(list[list.length - 1]);
            break;
        case 'ArrowRight':
            if (!row.hasChildren) return;
            if (!row.expanded) toggle(row.node.id, true);
            else move(list[index + 1]?.parentId === row.node.id ? list[index + 1] : null);
            break;
        case 'ArrowLeft':
            if (row.hasChildren && row.expanded) toggle(row.node.id, false);
            else if (row.parentId !== null) focusRow(row.parentId);
            else return;
            break;
        case 'Enter':
        case ' ':
            select(row.node.id);
            break;
        default:
            return;
    }
    event.preventDefault();
}

// ── Copy reminder: there is no messaging integration, so the reviewer pastes it themselves ──
async function copyReminder(node) {
    const text = reminderText(node, { weekStart: props.week.start, reportDue: props.week.reportDue, origin: window.location.origin });

    try {
        await navigator.clipboard.writeText(text);
        toast.success('Reminder copied', { description: `${unitLabel(node)}, week of ${forumDate(props.week.start)}. Paste it to the leader.` });
    } catch {
        toast.error('Could not copy the reminder', { description: 'The browser blocked clipboard access. Allow it for this site and try again.' });
    }
}

const SEGMENT_COLOR = { sent: 'bg-success', draft: 'bg-warning', not_started: 'bg-border' };

function pct(ratio) {
    const value = percent(ratio);

    return value === null ? 'n/a' : `${value}%`;
}
</script>

<template>
    <Head :title="`Reports · ${scopeName ?? '7Cav'} · 7Cav Leadership Portal`" />

    <div class="space-y-4">
        <!-- Page header: whose reports, which week, when they are due. -->
        <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">{{ scopeName ? `${scopeName} weekly reports` : 'Weekly reports' }}</p>
                <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]">Week of {{ forumDate(week.start) }}</h1>
            </div>
            <div class="flex items-center gap-1 text-muted-foreground">
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Previous week"
                    :disabled="weekIndex >= week.options.length - 1"
                    @click="goToWeek(week.options[weekIndex + 1])"
                >
                    <PhCaretLeft />
                </Button>
                <label for="week-select" class="sr-only">Week</label>
                <NativeSelect
                    id="week-select"
                    class="h-7 w-auto text-xs"
                    :model-value="weekKey"
                    data-testid="week-select"
                    @update:model-value="goToWeek"
                >
                    <NativeSelectOption v-for="(option, index) in week.options" :key="option" :value="option">
                        {{ index === 0 ? `This week, ${forumDate(option)}` : `Week of ${forumDate(option)}` }}
                    </NativeSelectOption>
                </NativeSelect>
                <Button variant="ghost" size="icon-sm" aria-label="Next week" :disabled="weekIndex <= 0" @click="goToWeek(week.options[weekIndex - 1])">
                    <PhCaretRight />
                </Button>
            </div>
            <div class="w-full sm:ml-auto sm:w-auto sm:text-right">
                <p class="text-xs text-muted-foreground">Due {{ dayTime(week.reportDue) }}</p>
                <p v-if="week.isCurrent" class="text-lg leading-tight font-semibold tabular-nums" data-testid="countdown">{{ countdown }}</p>
                <p v-else class="text-lg leading-tight font-semibold text-muted-foreground">Closed week</p>
            </div>
        </div>

        <Card v-if="!roots.length" class="py-0">
            <EmptyState :icon="PhTreeStructure" title="No units in your scope yet" class="py-14">
                Reports show here for the units you lead or hold a staff grant over. If that should include a unit, ask an
                admin to check your billet mapping or grants.
            </EmptyState>
        </Card>

        <div v-else class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_28rem]">
            <!-- Roll-up -->
            <Card role="region" class="min-w-0 gap-0 py-0" aria-labelledby="rollup-title">
                <div class="space-y-3 px-4 pt-3.5 pb-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h2 id="rollup-title" class="text-[15px] font-semibold">Command roll-up</h2>
                        <span class="text-xs text-muted-foreground">Rolls up from sent reports only</span>
                        <div class="ml-auto inline-flex rounded-md border border-border bg-card p-0.5" role="group" aria-label="Filter reports">
                            <button
                                v-for="option in [{ value: 'open', label: 'Not sent', count: openCount }, { value: 'all', label: 'All', count: allCount }]"
                                :key="option.value"
                                type="button"
                                class="inline-flex h-6 items-center gap-1.5 rounded-[5px] px-2 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-accent aria-pressed:text-foreground"
                                :aria-pressed="filter === option.value"
                                :data-testid="`filter-${option.value}`"
                                @click="filter = option.value"
                            >
                                {{ option.label }} <span class="tabular-nums opacity-70">{{ option.count }}</span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <p id="progress-label" class="text-[13px] font-medium tabular-nums" data-testid="progress-label">{{ progress.label }}</p>
                            <p class="text-xs text-muted-foreground tabular-nums">
                                {{ [...progress.echelons, progress.rootStatus && `${unitLabel(roots[0])} ${progress.rootStatus}`].filter(Boolean).join(', ') }}
                            </p>
                        </div>
                        <div
                            class="flex h-2 gap-0.5 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            aria-labelledby="progress-label"
                            aria-valuemin="0"
                            :aria-valuemax="progress.total"
                            :aria-valuenow="progress.sent"
                        >
                            <span
                                v-for="segment in progress.segments.filter((s) => s.count)"
                                :key="segment.status"
                                class="h-full"
                                :class="SEGMENT_COLOR[segment.status]"
                                :style="{ width: `${(segment.count / progress.total) * 100}%` }"
                            />
                        </div>
                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span v-for="segment in progress.segments" :key="segment.status" class="inline-flex items-center gap-1.5 tabular-nums">
                                <span class="inline-block size-2.5 rounded-sm" :class="SEGMENT_COLOR[segment.status]" aria-hidden="true" />
                                {{ segment.label }} {{ segment.count }}
                            </span>
                        </p>
                    </div>
                </div>

                <div class="border-t border-border">
                    <div class="rt-grid border-b border-border px-3 py-2 text-xs text-muted-foreground" aria-hidden="true">
                        <span>Unit</span>
                        <span class="rt-num rt-wide">Strength</span>
                        <span class="rt-num">RC %</span>
                        <span class="rt-num">SP %</span>
                        <span class="rt-num rt-wide">At-risk</span>
                        <span>Report</span>
                    </div>

                    <EmptyState v-if="!rows.length" :icon="PhChecks" title="Every report in this chain is sent" class="py-12">
                        Nothing is waiting this week.
                        <Button variant="outline" size="sm" class="mt-3" @click="filter = 'all'">Show all reports</Button>
                    </EmptyState>

                    <div v-else ref="treeEl" role="treegrid" :aria-label="`Report roll-up for ${scopeName ?? 'your scope'}`" aria-readonly="true">
                        <div
                            v-for="row in rows"
                            :key="row.node.id"
                            role="row"
                            class="rt-grid rt-row"
                            :data-row-id="row.node.id"
                            :data-level="row.level"
                            :aria-level="row.level"
                            :aria-expanded="row.hasChildren ? row.expanded : undefined"
                            :aria-selected="selectedId === row.node.id"
                            :tabindex="focusedId === row.node.id ? 0 : -1"
                            @click="select(row.node.id)"
                            @focus="focusedId = row.node.id"
                            @keydown="onKeydown($event, row)"
                        >
                            <div role="gridcell" class="flex min-w-0 items-center gap-1.5" :style="{ paddingLeft: `calc(1.25rem * ${row.level - 1})` }">
                                <button
                                    v-if="row.hasChildren"
                                    type="button"
                                    tabindex="-1"
                                    class="rt-caret"
                                    :aria-label="`${row.expanded ? 'Collapse' : 'Expand'} ${unitLabel(row.node)}`"
                                    :aria-expanded="row.expanded"
                                    @click.stop="toggle(row.node.id)"
                                >
                                    <PhCaretDown />
                                </button>
                                <span v-else class="w-5 flex-none" />
                                <span class="min-w-0">
                                    <span class="block truncate">{{ unitLabel(row.node) }}</span>
                                    <span v-if="row.node.callsign" class="block truncate text-xs font-normal text-muted-foreground">{{ row.node.name }}</span>
                                </span>
                            </div>
                            <div role="gridcell" class="rt-num rt-wide" :class="{ 'text-muted-foreground': row.node.live && !row.hasChildren }">
                                {{ row.node.kpis.strength }}
                            </div>
                            <div role="gridcell" class="rt-num" :class="{ 'text-muted-foreground': row.node.live && !row.hasChildren }">
                                {{ pct(row.node.kpis.rc) }}
                                <small v-if="row.node.coverage && row.node.status !== 'sent'">{{ row.node.coverage.sent }} of {{ row.node.coverage.of }} in</small>
                            </div>
                            <div role="gridcell" class="rt-num" :class="{ 'text-muted-foreground': row.node.live && !row.hasChildren }">
                                {{ pct(row.node.kpis.sp) }}
                            </div>
                            <div
                                role="gridcell"
                                class="rt-num rt-wide"
                                :class="row.node.live && !row.hasChildren ? 'text-muted-foreground' : row.node.kpis.atRisk ? 'text-warning' : 'text-muted-foreground'"
                            >
                                {{ row.node.kpis.atRisk }}
                            </div>
                            <div role="gridcell"><StatusPill :status="displayStatus(row.node)" /></div>
                        </div>
                    </div>
                </div>

                <p class="flex flex-wrap gap-x-4 gap-y-1 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                    <span>RC and SP are 30-day rates.</span>
                    <span class="inline-flex items-center gap-1"><PhLightning aria-hidden="true" />Grey figures are live from attendance, not yet sent.</span>
                </p>
            </Card>

            <!-- The selected report: docked beside the tree from 1280px, a Sheet below that. -->
            <Card
                v-if="docked && selected"
                role="region"
                aria-labelledby="report-title"
                aria-live="polite"
                class="sticky top-16 max-h-[calc(100dvh-7.5rem)] min-w-0 gap-0 overflow-hidden py-0"
            >
                <RollupReport :node="selected" :by-id="byId" :week="week" @select="(id) => select(id, { openSheet: false })" @copy-reminder="copyReminder" />
            </Card>
        </div>

        <Sheet v-if="!docked" v-model:open="sheetOpen">
            <SheetContent side="right" class="w-full gap-0 p-0 sm:max-w-[31rem]">
                <SheetTitle class="sr-only">{{ selected ? `${unitLabel(selected)} report` : 'Report' }}</SheetTitle>
                <SheetDescription class="sr-only">The selected unit's report for the week of {{ forumDate(week.start) }}.</SheetDescription>
                <RollupReport
                    v-if="selected"
                    :node="selected"
                    :by-id="byId"
                    :week="week"
                    title-id="sheet-report-title"
                    @select="(id) => select(id, { openSheet: false })"
                    @copy-reminder="copyReminder"
                />
            </SheetContent>
        </Sheet>
    </div>
</template>

<style scoped>
.rt-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 2.75rem 2.75rem 6.75rem;
    align-items: center;
    column-gap: 0.375rem;
}
.rt-wide {
    display: none;
}
@media (min-width: 640px) {
    .rt-grid {
        grid-template-columns: minmax(13rem, 1fr) 4.25rem 4.5rem 4.5rem 4rem 7.5rem;
        column-gap: 0.5rem;
    }
    .rt-wide {
        display: block;
    }
}
.rt-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.rt-num small {
    display: block;
    font-size: 0.6875rem;
    line-height: 1.1;
    font-weight: 400;
    color: var(--muted-foreground);
}
.rt-row {
    position: relative;
    cursor: pointer;
    padding: 0.4375rem 0.75rem;
    font-size: 0.8125rem;
    border-bottom: 1px solid color-mix(in oklab, var(--border) 70%, transparent);
    outline: none;
}
.rt-row:hover {
    background: color-mix(in oklab, var(--accent) 60%, transparent);
}
.rt-row:focus-visible {
    box-shadow: inset 0 0 0 2px var(--ring);
}
.rt-row[aria-selected='true'] {
    background: var(--accent);
    box-shadow: inset 2px 0 0 var(--primary);
}
.rt-row[aria-selected='true']:focus-visible {
    box-shadow: inset 2px 0 0 var(--primary), inset 0 0 0 2px var(--ring);
}
.rt-row[data-level='1'] {
    background: color-mix(in oklab, var(--muted) 70%, transparent);
}
.rt-row[data-level='1'][aria-selected='true'] {
    background: var(--accent);
}
.rt-row[data-level='2'] {
    font-weight: 500;
}
.rt-caret {
    display: grid;
    flex: none;
    place-items: center;
    width: 1.25rem;
    height: 1.25rem;
    border-radius: calc(var(--radius) - 2px);
    color: var(--muted-foreground);
}
.rt-caret:hover {
    background: var(--accent);
    color: var(--foreground);
}
.rt-caret :deep(svg) {
    transition: transform 0.15s;
}
.rt-caret[aria-expanded='false'] :deep(svg) {
    transform: rotate(-90deg);
}
@media (prefers-reduced-motion: reduce) {
    .rt-caret :deep(svg) {
        transition: none;
    }
}
</style>
