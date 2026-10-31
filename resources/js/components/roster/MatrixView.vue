<script setup>
import { slotLabel } from '@/lib/practiceSlot';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhArrowSquareIn, PhArrowsOutLineVertical, PhArrowsInLineVertical, PhCaretDown, PhCaretRight, PhCaretUpDown, PhLockSimple, PhMagnifyingGlass, PhSortAscending, PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import EmptyState from '@/components/EmptyState.vue';
import MissingIdentityIcon from '@/components/MissingIdentityIcon.vue';
import NoValue from '@/components/NoValue.vue';
import PracticeSlotMissingIcon from '@/components/PracticeSlotMissingIcon.vue';
import {
    aggregateWeek,
    displayName,
    FILTERS,
    matchesQuery,
    percentLabel,
    percentTone,
    RC_STATES,
    sourceLabel,
    SORTS,
    SP_STATES,
    weekCell,
    weekLabel,
    weekTick,
} from '@/lib/roster';
import { vStickyHead } from '@/lib/stickyHead';
import CellLegend from './CellLegend.vue';
import FilterChips from './FilterChips.vue';
import FloatingTip from './FloatingTip.vue';
import WeekCell from './WeekCell.vue';

// Attendance matrix (binding mockup roster-s3.html): one row per trooper, one split cell per Cav
// week (top rollcall, bottom SP), the current week outlined, name column and the RC/SP/at-risk
// columns pinned. At platoon scope and wider, sections fold into aggregate rows. An ARIA grid:
// arrow keys move between cells, Home/End jump along a row, Enter opens the trooper.
const props = defineProps({
    units: { type: Array, required: true },
    troopers: { type: Array, required: true },
    weeks: { type: Array, required: true },
    currentWeek: { type: String, required: true },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits(['open']);
const query = defineModel('query', { type: String, default: '' });
const filter = defineModel('filter', { type: String, default: 'all' });

const win = ref('20');
// A single-select group emits an empty value when the active button is pressed again; keep the
// current window rather than rendering zero (NaN) week columns.
function selectWindow(value) {
    if (value) win.value = value;
}
const sortKey = ref('billet');
const range = computed(() => {
    const count = Number(win.value);

    return Array.from({ length: count }, (_, k) => props.weeks.length - count + k);
});

const root = computed(() => props.units[0]);
const grouped = computed(() => root.value.type !== 'section');
const filtering = computed(() => query.value.trim() !== '' || filter.value !== 'all');
const visible = (t) => FILTERS[filter.value](t, props.currentWeek) && matchesQuery(t, query.value);

const sections = computed(() => props.units.filter((u) => u.type === 'section'));
const unitById = computed(() => new Map(props.units.map((u) => [u.id, u])));
const membersOf = computed(() => {
    const map = new Map(sections.value.map((s) => [s.id, []]));
    for (const trooper of props.troopers) map.get(trooper.sectionId)?.push(trooper);

    return map;
});
const activeMembers = (sectionId) => (membersOf.value.get(sectionId) ?? []).filter((t) => !t.departed);

const expanded = ref(new Set(sections.value.length <= 4 ? sections.value.map((s) => s.id) : []));
function toggle(id) {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}
const allExpanded = computed(() => sections.value.every((s) => expanded.value.has(s.id)));
function toggleAll() {
    expanded.value = allExpanded.value ? new Set() : new Set(sections.value.map((s) => s.id));
}

function withAggregates(members) {
    return { members, agg: Object.fromEntries(range.value.map((i) => [i, aggregate(members, i)])) };
}

const sorted = (list) => [...list].sort(SORTS[sortKey.value].compare);

// Rows in order, each focusable element numbered for grid navigation.
const rows = computed(() => {
    const out = [];
    let r = 0;
    if (!grouped.value) {
        const members = sorted(props.troopers.filter(visible));
        members.forEach((trooper) => out.push({ kind: 'trooper', trooper, r: r++, nested: false }));
        if (members.length && !filtering.value) out.push({ kind: 'average', unit: root.value, ...withAggregates(activeMembers(root.value.id)), r: r++ });

        return out;
    }

    let order = sections.value;
    if (sortKey.value === 'risk') order = [...order].sort((a, b) => b.kpis.atRisk - a.kpis.atRisk);
    const labelled = new Set();
    for (const section of order) {
        const members = sorted((membersOf.value.get(section.id) ?? []).filter(visible));
        if (filtering.value && !members.length) continue;
        const parent = unitById.value.get(section.parentId);
        if (sortKey.value !== 'risk' && parent && parent.id !== root.value.id && !labelled.has(parent.id)) {
            labelled.add(parent.id);
            out.push({ kind: 'label', unit: parent });
        }
        const open = filtering.value || expanded.value.has(section.id);
        out.push({ kind: 'section', unit: section, ...withAggregates(activeMembers(section.id)), open, r: r++ });
        if (open) members.forEach((trooper) => out.push({ kind: 'trooper', trooper, r: r++, nested: true }));
    }

    return out;
});
const anyRows = computed(() => rows.value.some((row) => row.kind === 'trooper' || row.kind === 'section'));

function cell(trooper, index) {
    const week = props.weeks[index];

    return weekCell(trooper.weeks[index], week, { current: week === props.currentWeek, joined: trooper.joined, excused: trooper.excusedWeeks?.[week] ?? null });
}
function aggregate(members, index) {
    const week = props.weeks[index];

    return aggregateWeek(members, index, week, week === props.currentWeek);
}

function cellLabel(trooper, index) {
    const c = cell(trooper, index);

    return `${weekLabel(props.weeks[index])}. Roll call: ${RC_STATES[c.rc]}. SP: ${SP_STATES[c.sp]}.`;
}

function aggregateLabel(row, i) {
    const agg = row.agg[i];
    const rc = agg.due ? `${agg.signed} of ${agg.due} signed` : RC_STATES[agg.rc] ?? 'no data';
    const sp = agg.marked ? `${agg.attended} of ${agg.marked} attended` : SP_STATES[agg.sp] ?? 'no data';

    return `${row.unit.callsign ?? row.unit.name}, week ${weekLabel(props.weeks[i])}. Roll call ${rc}. SP ${sp}.`;
}

const byId = computed(() => new Map(props.troopers.map((t) => [t.id, t])));
function resolveTip(key) {
    const [kind, id, indexText] = key.split(':');
    const index = Number(indexText);
    const week = props.weeks[index];
    const current = week === props.currentWeek;
    if (kind === 'm') {
        const trooper = byId.value.get(Number(id));
        const mark = trooper.weeks[index];
        const c = cell(trooper, index);

        return {
            title: `${trooper.displayName}, week ${weekLabel(week)}${current ? ' (this week)' : ''}`,
            rows: [
                ['Roll call', RC_STATES[c.rc], c.rc === 'open' ? 'Roll call still open' : null],
                ['SP', SP_STATES[c.sp], sourceLabel(mark)],
            ],
        };
    }
    const unit = unitById.value.get(Number(id));
    const agg = aggregate(activeMembers(unit.id), index);

    return {
        title: `${unit.callsign ?? unit.name}, week ${weekLabel(week)}${current ? ' (this week)' : ''}`,
        rows: [
            ['Roll call', agg.due ? `${agg.signed} of ${agg.due} signed` : RC_STATES[agg.rc] ?? 'n/a'],
            ['SP', agg.marked ? `${agg.attended} of ${agg.marked} attended` : SP_STATES[agg.sp] ?? 'n/a'],
            ...(agg.loa ? [['LOA', `${agg.loa} trooper${agg.loa === 1 ? '' : 's'}`]] : []),
        ],
    };
}

const toneClass = { bad: 'text-destructive', warn: 'text-warning', ok: 'text-success', muted: 'text-muted-foreground' };

// Roving tabindex: exactly one grid element is in the tab order at a time.
const wrap = ref(null);
const focusKey = ref('0:0');
function onGridKey(event) {
    const el = event.target.closest?.('[data-nav]');
    if (!el) return;
    let r = Number(el.dataset.r);
    let c = Number(el.dataset.c);
    const moves = { ArrowRight: [0, 1], ArrowLeft: [0, -1], ArrowDown: [1, 0], ArrowUp: [-1, 0] };
    if (moves[event.key]) {
        r += moves[event.key][0];
        c += moves[event.key][1];
    } else if (event.key === 'Home') c = 0;
    else if (event.key === 'End') c = range.value.length;
    else if ((event.key === 'Enter' || event.key === ' ') && el.dataset.open) {
        event.preventDefault();
        emit('open', Number(el.dataset.open));

        return;
    } else return;
    event.preventDefault();
    const next = wrap.value.querySelector(`[data-nav][data-r="${r}"][data-c="${c}"]`);
    if (!next) return;
    focusKey.value = `${r}:${c}`;
    next.focus();
    next.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}
const tabFor = (r, c) => (focusKey.value === `${r}:${c}` ? 0 : -1);
watch(rows, () => (focusKey.value = '0:0'));

// Open on the current week: the newest columns sit at the right edge.
async function scrollToNow() {
    await nextTick();
    if (wrap.value) wrap.value.scrollLeft = wrap.value.scrollWidth;
}
onMounted(scrollToNow);
watch(win, scrollToNow);

function clearAll() {
    query.value = '';
    filter.value = 'all';
}
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-[10px] border border-border bg-card" aria-labelledby="matrix-title" data-testid="roster-matrix">
        <h2 id="matrix-title" class="sr-only">Attendance by Cav week</h2>
        <div class="flex flex-wrap items-center gap-2 border-b border-border px-3 py-3 sm:px-4">
            <label class="relative w-full sm:w-52">
                <span class="sr-only">Filter troopers by name, rank or billet</span>
                <PhMagnifyingGlass class="absolute top-1/2 left-2.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    placeholder="Filter by name"
                    class="h-8 w-full rounded-md border border-input bg-card pr-2.5 pl-8 text-[13px] placeholder:text-muted-foreground focus-visible:border-transparent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    data-testid="matrix-search"
                    @keydown.esc="query = ''"
                />
            </label>
            <FilterChips v-model="filter" :troopers="troopers" :current-week="currentWeek" />
            <div class="ml-auto flex items-center gap-2">
                <ToggleGroup :model-value="win" type="single" variant="segment" aria-label="Weeks shown" @update:model-value="selectWindow">
                    <ToggleGroupItem value="8" data-testid="window-8">8 wk</ToggleGroupItem>
                    <ToggleGroupItem value="20" data-testid="window-20">20 wk</ToggleGroupItem>
                </ToggleGroup>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" data-testid="matrix-sort"><PhSortAscending />{{ SORTS[sortKey].label }}</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Sort troopers</DropdownMenuLabel>
                        <DropdownMenuRadioGroup v-model="sortKey">
                            <DropdownMenuRadioItem v-for="(sort, key) in SORTS" :key="key" :value="key" :data-testid="`sort-${key}`">{{ sort.label }}</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button v-if="grouped" variant="ghost" size="sm" :disabled="filtering" @click="toggleAll">
                    <component :is="allExpanded ? PhArrowsInLineVertical : PhArrowsOutLineVertical" />{{ allExpanded ? 'Collapse all' : 'Expand all' }}
                </Button>
            </div>
        </div>

        <div ref="wrap" v-sticky-head="'box'" class="overflow-auto overscroll-x-contain lg:[--sticky-box-gap:12.5rem]" @keydown="onGridKey">
            <table class="rm min-w-full border-separate border-spacing-0 text-[13px]" role="grid" aria-labelledby="matrix-title" aria-describedby="roster-legend" :aria-busy="loading || undefined">
                <thead>
                    <tr>
                        <th class="sl" scope="col">Trooper</th>
                        <th
                            v-for="i in range"
                            :key="weeks[i]"
                            scope="col"
                            class="wk"
                            :class="weeks[i] === currentWeek && 'is-now'"
                            :title="`Cav week ${weekLabel(weeks[i])}${weeks[i] === currentWeek ? ', this week' : ''}`"
                        >
                            {{ weekTick(weeks[i]) }}
                        </th>
                        <th class="sum sum-rc" scope="col" :aria-sort="sortKey === 'rc' ? 'ascending' : 'none'">
                            <button type="button" class="ml-auto inline-flex items-center gap-1 hover:text-foreground" title="Sort by RC 30d" @click="sortKey = 'rc'">
                                RC 30d<component :is="sortKey === 'rc' ? PhCaretDown : PhCaretUpDown" class="size-3" />
                            </button>
                        </th>
                        <th class="sum sum-sp" scope="col" :aria-sort="sortKey === 'sp' ? 'ascending' : 'none'">
                            <button type="button" class="ml-auto inline-flex items-center gap-1 hover:text-foreground" title="Sort by SP 30d" @click="sortKey = 'sp'">
                                SP 30d<component :is="sortKey === 'sp' ? PhCaretDown : PhCaretUpDown" class="size-3" />
                            </button>
                        </th>
                        <th class="sum sum-risk" scope="col" :aria-sort="sortKey === 'risk' ? 'descending' : 'none'">
                            <button type="button" class="inline-flex items-center gap-1 hover:text-foreground" title="Sort at-risk first" data-testid="sort-risk-header" @click="sortKey = 'risk'">
                                At-risk<component :is="sortKey === 'risk' ? PhCaretDown : PhCaretUpDown" class="size-3" />
                            </button>
                        </th>
                    </tr>
                </thead>

                <tbody v-if="loading" aria-hidden="true">
                    <tr v-for="n in 7" :key="n">
                        <td class="sl"><Skeleton class="mb-1.5 h-3.5 w-28" /><Skeleton class="h-3 w-20" /></td>
                        <td v-for="i in range" :key="i" class="wk"><Skeleton class="mx-auto h-7 w-[1.625rem]" /></td>
                        <td class="sum sum-rc"><Skeleton class="ml-auto h-3.5 w-8" /></td>
                        <td class="sum sum-sp"><Skeleton class="ml-auto h-3.5 w-8" /></td>
                        <td class="sum sum-risk"><Skeleton class="h-4 w-14" /></td>
                    </tr>
                </tbody>

                <tbody v-else-if="!anyRows">
                    <tr>
                        <td :colspan="range.length + 4" class="!py-12">
                            <div class="sticky left-0 flex w-[min(100%,calc(100vw-3rem))] flex-col items-center">
                                <EmptyState :icon="PhMagnifyingGlass" title="No troopers match these filters">Clear the filters to see everyone in this unit.</EmptyState>
                                <Button variant="outline" size="sm" class="mt-3" @click="clearAll">Clear filters</Button>
                            </div>
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <template v-for="row in rows" :key="`${row.kind}-${row.unit?.id ?? row.trooper.id}`">
                        <tr v-if="row.kind === 'label'" class="tr-label">
                            <td class="sl">
                                <span class="font-medium text-foreground">{{ row.unit.callsign ?? row.unit.name }}</span>
                                <span v-if="row.unit.leader" class="hidden sm:inline">{{ ' ' }}{{ row.unit.leader.displayName }}</span>
                            </td>
                            <td :colspan="range.length + 3">Headcount {{ row.unit.kpis.strength }}</td>
                        </tr>

                        <tr v-else-if="row.kind === 'section' || row.kind === 'average'" :class="row.kind === 'average' ? 'tr-avg' : 'tr-agg'" :data-testid="`agg-${row.unit.id}`">
                            <td class="sl" role="rowheader">
                                <div class="flex items-center gap-1">
                                    <button
                                        v-if="row.kind === 'section'"
                                        type="button"
                                        class="nm-btn min-w-0 flex-1"
                                        data-nav
                                        :data-r="row.r"
                                        data-c="0"
                                        :tabindex="tabFor(row.r, 0)"
                                        :aria-expanded="row.open"
                                        :data-testid="`matrix-toggle-${row.unit.id}`"
                                        @click="toggle(row.unit.id)"
                                    >
                                        <span class="flex items-center gap-1.5">
                                            <component :is="row.open ? PhCaretDown : PhCaretRight" class="size-3.5 text-muted-foreground" aria-hidden="true" />
                                            <span class="font-medium">{{ row.unit.callsign ?? row.unit.name }}</span>
                                            <PracticeSlotMissingIcon :missing="row.unit.practiceSlotMissing ?? false" />
                                        </span>
                                        <span class="block truncate pl-5 text-xs text-muted-foreground">
                                            <template v-if="row.unit.leader">{{ row.unit.leader.displayName }}, </template>{{ row.members.length }} troopers
                                        </span>
                                        <span v-if="row.unit.practiceSlot" class="block truncate pl-5 text-xs text-muted-foreground" :data-testid="`matrix-slot-${row.unit.id}`">
                                            Practice {{ slotLabel(row.unit.practiceSlot) }}
                                        </span>
                                    </button>
                                    <span v-else class="nm-btn" data-nav :data-r="row.r" data-c="0" :tabindex="tabFor(row.r, 0)">
                                        <span class="block font-medium">Section average</span>
                                        <span class="block text-xs text-muted-foreground">{{ row.members.length }} troopers</span>
                                    </span>
                                    <Button v-if="row.kind === 'section'" as-child variant="ghost" size="icon-sm" class="flex-none">
                                        <Link :href="`/roster/${row.unit.id}?view=matrix`" :aria-label="`Show ${row.unit.callsign ?? row.unit.name} only`" :title="`Show ${row.unit.callsign ?? row.unit.name} only`">
                                            <PhArrowSquareIn />
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                            <td v-for="(i, k) in range" :key="i" class="wk" :class="weeks[i] === currentWeek && 'is-now'" role="gridcell">
                                <span
                                    class="cc-focus"
                                    data-nav
                                    :data-r="row.r"
                                    :data-c="k + 1"
                                    :tabindex="tabFor(row.r, k + 1)"
                                    :data-tip="`s:${row.unit.id}:${i}`"
                                    :aria-label="aggregateLabel(row, i)"
                                >
                                    <WeekCell :rc="row.agg[i].rc" :sp="row.agg[i].sp" />
                                </span>
                            </td>
                            <td class="sum sum-rc font-medium" :class="toneClass[percentTone(row.unit.kpis.rc)]">{{ percentLabel(row.unit.kpis.rc) }}</td>
                            <td class="sum sum-sp font-medium" :class="toneClass[percentTone(row.unit.kpis.sp)]">{{ percentLabel(row.unit.kpis.sp) }}</td>
                            <td class="sum sum-risk">
                                <Badge v-if="row.unit.kpis.atRisk" variant="warning" class="tabular-nums">{{ row.unit.kpis.atRisk }} at-risk</Badge>
                                <span v-else class="text-muted-foreground">None</span>
                            </td>
                        </tr>

                        <tr v-else class="tr-trooper" :class="(row.trooper.kpis?.onLoa || row.trooper.departed) && 'is-muted'" :data-testid="`matrix-trooper-${row.trooper.id}`" @click="emit('open', row.trooper.id)">
                            <td class="sl" role="rowheader">
                                <button
                                    type="button"
                                    class="nm-btn"
                                    :class="row.nested && 'pl-5'"
                                    data-nav
                                    :data-r="row.r"
                                    data-c="0"
                                    :data-open="row.trooper.id"
                                    :tabindex="tabFor(row.r, 0)"
                                    @click.stop="emit('open', row.trooper.id)"
                                >
                                    <span class="flex items-center gap-1">
                                        <span class="rank">{{ row.trooper.rank }}</span>
                                        <span class="nm-name truncate font-medium">{{ displayName(row.trooper) }}</span>
                                        <PhLockSimple v-if="row.trooper.kpis?.onLoa" class="text-muted-foreground" aria-label="On LOA" />
                                        <MissingIdentityIcon :game="row.trooper.missingGame ?? null" />
                                    </span>
                                    <span class="block max-w-[12rem] truncate pl-[2.35rem] text-xs text-muted-foreground">{{ row.trooper.departed ? 'Departed' : row.trooper.billet ?? 'No billet on record' }}</span>
                                </button>
                            </td>
                            <td v-for="(i, k) in range" :key="i" class="wk" :class="weeks[i] === currentWeek && 'is-now'" role="gridcell">
                                <span
                                    class="cc-focus"
                                    data-nav
                                    :data-r="row.r"
                                    :data-c="k + 1"
                                    :data-open="row.trooper.id"
                                    :tabindex="tabFor(row.r, k + 1)"
                                    :data-tip="`m:${row.trooper.id}:${i}`"
                                    :aria-label="cellLabel(row.trooper, i)"
                                >
                                    <WeekCell v-bind="cell(row.trooper, i)" :auto="row.trooper.weeks[i]?.source === 'auto'" />
                                </span>
                            </td>
                            <td class="sum sum-rc" :class="toneClass[percentTone(row.trooper.kpis?.rc)]"><template v-if="row.trooper.kpis">{{ percentLabel(row.trooper.kpis.rc) }}</template><NoValue v-else /></td>
                            <td class="sum sum-sp" :class="toneClass[percentTone(row.trooper.kpis?.sp)]"><template v-if="row.trooper.kpis">{{ percentLabel(row.trooper.kpis.sp) }}</template><NoValue v-else /></td>
                            <td class="sum sum-risk">
                                <Badge v-if="row.trooper.kpis?.onLoa" variant="outline"><PhLockSimple />LOA</Badge>
                                <Badge v-else-if="row.trooper.kpis?.atRisk" variant="warning" :title="`Missed ${row.trooper.kpis.missed} of the last 4 section practices`" data-testid="matrix-at-risk"><PhWarning />At-risk</Badge>
                                <span v-else class="text-muted-foreground">None</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <CellLegend aggregate class="border-t border-border" />
        <FloatingTip :container="wrap" :resolve="resolveTip" />
    </section>
</template>

<style scoped>
.rm th {
    position: sticky;
    top: 0;
    z-index: 3;
    padding: 0.5rem 0.25rem;
    border-bottom: 1px solid var(--border);
    background: var(--card);
    color: var(--muted-foreground);
    font-size: 0.6875rem;
    font-weight: 500;
    text-align: left;
    white-space: nowrap;
}
.rm td {
    padding: 0.3125rem 0.25rem;
    border-bottom: 1px solid color-mix(in oklab, var(--border) 60%, transparent);
    background-color: var(--card);
    white-space: nowrap;
    vertical-align: middle;
}
.rm .sl {
    position: sticky;
    left: 0;
    z-index: 2;
    min-width: 10.5rem;
    max-width: 15rem;
    padding-left: 1rem;
    padding-right: 0.75rem;
    overflow: hidden;
    text-overflow: ellipsis;
    box-shadow: 1px 0 0 var(--border);
}
@media (max-width: 639px) {
    .rm .sl {
        min-width: 8.75rem;
        max-width: 9.5rem;
        padding-left: 0.75rem;
        padding-right: 0.5rem;
    }
}
.rm th.sl {
    z-index: 5;
}
.rm .wk {
    width: 2.875rem;
    min-width: 2.875rem;
    padding-left: 0;
    padding-right: 0;
    text-align: center;
}
.rm th.wk {
    font-size: 0.65625rem;
    font-variant-numeric: tabular-nums;
    padding-left: 2px;
    padding-right: 2px;
}
.rm .is-now {
    background-image: linear-gradient(color-mix(in oklab, var(--primary) 9%, transparent), color-mix(in oklab, var(--primary) 9%, transparent));
    box-shadow:
        inset 1.5px 0 0 var(--primary),
        inset -1.5px 0 0 var(--primary);
}
.rm th.is-now {
    color: var(--foreground);
    font-weight: 600;
    box-shadow:
        inset 1.5px 0 0 var(--primary),
        inset -1.5px 0 0 var(--primary),
        inset 0 1.5px 0 var(--primary);
}
.rm tbody tr:last-child td.is-now {
    box-shadow:
        inset 1.5px 0 0 var(--primary),
        inset -1.5px 0 0 var(--primary),
        inset 0 -1.5px 0 var(--primary);
}
.rm .sum {
    box-sizing: border-box;
    width: 4.25rem;
    min-width: 4.25rem;
    max-width: 4.25rem;
    padding-right: 0.625rem;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.rm .sum-risk {
    width: 6.75rem;
    min-width: 6.75rem;
    max-width: 6.75rem;
    padding-left: 0.625rem;
    padding-right: 0.75rem;
    text-align: left;
}
@media (min-width: 768px) {
    .rm .sum {
        position: sticky;
        z-index: 2;
    }
    .rm th.sum {
        z-index: 5;
    }
    .rm .sum-risk {
        right: 0;
    }
    .rm .sum-sp {
        right: 6.75rem;
    }
    .rm .sum-rc {
        right: 11rem;
        box-shadow: -1px 0 0 var(--border);
    }
}
.rm .tr-trooper {
    cursor: pointer;
}
.rm .tr-trooper:hover td {
    background-color: var(--accent);
}
.rm .tr-agg td,
.rm .tr-avg td {
    background-color: var(--muted);
    border-bottom-color: var(--border);
}
.rm .tr-agg:hover td {
    background-color: var(--accent);
}
.rm .tr-label td {
    padding-top: 0.75rem;
    padding-bottom: 0.375rem;
    background-color: var(--background);
    color: var(--muted-foreground);
    font-size: 0.75rem;
}
.rm .is-muted .nm-name {
    color: var(--muted-foreground);
}
.nm-btn {
    display: block;
    width: 100%;
    border-radius: 4px;
    text-align: left;
}
.rank {
    display: inline-block;
    min-width: 2.1rem;
    color: var(--muted-foreground);
    font-size: 0.6875rem;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
}
.cc-focus {
    display: inline-flex;
    border-radius: 4px;
}
.cc-focus:focus-visible,
.nm-btn:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
</style>
