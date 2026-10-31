<script setup>
import { computed, ref } from 'vue';
import {
    PhArrowDown,
    PhArrowsInLineVertical,
    PhArrowsOutLineVertical,
    PhArrowUp,
    PhCaretRight,
    PhCaretUpDown,
    PhLockSimple,
    PhMagnifyingGlass,
    PhSignOut,
    PhUserPlus,
    PhWarning,
    PhX,
} from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { forumDate } from '@/lib/forumDate';
import { vStickyHead } from '@/lib/stickyHead';
import EmptyState from '@/components/EmptyState.vue';
import Guidon from '@/components/Guidon.vue';
import NoValue from '@/components/NoValue.vue';
import { useSlashClaim } from '@/composables/useSlashShortcut';
import {
    aggregateWeek,
    compareOrbat,
    displayName,
    FILTERS,
    highlightParts,
    isNewRecruit,
    matchesQuery,
    monthsSince,
    nextOrbatSort,
    ORBAT_SORTS,
    percentLabel,
    percentTone,
    RC_STATES,
    readOrbatSort,
    shortDuration,
    sourceLabel,
    SP_STATES,
    weekCell,
    weekStartTick,
    weekLabel,
    writeOrbatSort,
} from '@/lib/roster';
import CellLegend from './CellLegend.vue';
import FilterChips from './FilterChips.vue';
import FloatingTip from './FloatingTip.vue';
import RankBadge from './RankBadge.vue';
import WeekCell from './WeekCell.vue';

// Order of battle (binding mockup roster-s2.html): the subtree as platoon headers and collapsible
// sections, the tagged leaders (SL, ASL, an acting lead) pinned on top, each trooper with an 8-week
// rollcall/SP strip, RC/SP 30d and flags. Searching highlights hits and opens every section that has one.
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

const STRIP = 8;
const stripStart = computed(() => props.weeks.length - STRIP);
const stripWeeks = computed(() => props.weeks.slice(stripStart.value));

const root = computed(() => props.units[0]);
const childrenOf = computed(() => {
    const map = new Map();
    for (const unit of props.units) {
        if (!map.has(unit.parentId)) map.set(unit.parentId, []);
        map.get(unit.parentId).push(unit);
    }

    return map;
});
const depthOf = computed(() => {
    const depth = new Map([[root.value.id, 0]]);
    for (const unit of props.units.slice(1)) depth.set(unit.id, (depth.get(unit.parentId) ?? 0) + 1);

    return depth;
});
// Every unit a trooper can be placed in under `unit`, itself included: sections, and since 2026-10-24 the HQ nodes
// (a platoon's PL and PSG sit on the platoon) and groups.
function sectionsUnder(unit) {
    if (unit.type === 'section') return [unit.id];

    return [unit.id, ...(childrenOf.value.get(unit.id) ?? []).flatMap(sectionsUnder)];
}

// A column sort orders the troopers inside each section; the sections themselves keep the order of battle. Null is
// the default billet order (the command team in chain-of-command order, then rank). The choice is remembered per
// browser.
const sort = ref(readOrbatSort());
function sortBy(key) {
    sort.value = nextOrbatSort(sort.value, key);
    writeOrbatSort(sort.value);
}
function resetSort() {
    sort.value = null;
    writeOrbatSort(null);
}
const ariaSort = (...keys) => (keys.includes(sort.value?.key) ? (sort.value.dir === 'asc' ? 'ascending' : 'descending') : undefined);
const sortIcon = (key) => (sort.value?.key !== key ? PhCaretUpDown : sort.value.dir === 'asc' ? PhArrowUp : PhArrowDown);

const membersBySection = computed(() => {
    const map = new Map();
    for (const trooper of props.troopers) {
        if (!map.has(trooper.sectionId)) map.set(trooper.sectionId, []);
        map.get(trooper.sectionId).push(trooper);
    }
    const compare = compareOrbat(sort.value);
    for (const list of map.values()) list.sort(compare);

    return map;
});

// Time in service and in grade, as of today. A departed trooper's stopped counting, as on the milpac.
const today = new Date().toISOString().slice(0, 10);
const tenure = (trooper, date) => (trooper.departed ? '—' : shortDuration(monthsSince(date, today)));

const searching = computed(() => query.value.trim() !== '' || filter.value !== 'all');
const visible = (t) => FILTERS[filter.value](t, props.currentWeek) && matchesQuery(t, query.value);

const sectionIds = computed(() => props.units.filter((u) => u.type === 'section').map((u) => u.id));
// A big subtree opens folded so its structure reads first; a company or smaller opens unfolded.
const collapsed = ref(new Set(sectionIds.value.length > 8 ? sectionIds.value : []));
function toggle(id) {
    if (searching.value) return;
    const next = new Set(collapsed.value);
    next.has(id) ? next.delete(id) : next.add(id);
    collapsed.value = next;
}
const expandAll = () => (collapsed.value = new Set());
const collapseAll = () => (collapsed.value = new Set(sectionIds.value));

function counts(list) {
    return {
        risk: list.filter((t) => t.kpis?.atRisk).length,
        loa: list.filter((t) => t.kpis?.onLoa).length,
        fresh: list.filter((t) => !t.departed && isNewRecruit(t, props.currentWeek)).length,
    };
}

// The table as a flat row list: group headers, section rows, and trooper rows.
const rows = computed(() => {
    const out = [];
    for (const unit of props.units) {
        const sections = sectionsUnder(unit);
        const all = sections.flatMap((id) => membersBySection.value.get(id) ?? []);
        const shown = all.filter(visible);
        if (searching.value && !shown.length) continue;

        if (unit.type !== 'section') {
            if (unit.id !== root.value.id) out.push({ kind: 'group', unit, depth: depthOf.value.get(unit.id), counts: counts(all) });
            // HQ staff and group members sit on the unit itself, listed under its header.
            for (const trooper of (membersBySection.value.get(unit.id) ?? []).filter(visible)) {
                out.push({ kind: 'member', trooper, pinned: false, pinnedLast: false });
            }
            continue;
        }

        const open = searching.value || !collapsed.value.has(unit.id);
        if (unit.id !== root.value.id) {
            out.push({ kind: 'section', unit, open, total: all.filter((t) => !t.departed).length, shown: shown.length, members: all, counts: counts(all) });
        }
        if (open || unit.id === root.value.id) {
            // Tagged leaders (#443) are pinned on top only in the default order; a column sort puts them where they fall.
            shown.forEach((trooper, index) => {
                const pinned = !sort.value && !!trooper.role;
                out.push({ kind: 'member', trooper, pinned, pinnedLast: pinned && !shown[index + 1]?.role });
            });
        }
    }

    return out;
});

const shownCount = computed(() => rows.value.filter((r) => r.kind === 'member').length);
const scopedCount = computed(() => props.troopers.filter((t) => !t.departed).length);
const sectionsShown = computed(() => rows.value.filter((r) => r.kind === 'section').length);

function cellsFor(trooper) {
    return stripWeeks.value.map((week, i) => {
        const index = stripStart.value + i;

        return {
            week,
            index,
            ...weekCell(trooper.weeks[index], week, { current: week === props.currentWeek, joined: trooper.joined, excused: trooper.excusedWeeks?.[week] ?? null }),
            auto: trooper.weeks[index]?.source === 'auto',
        };
    });
}
function aggregateCells(members) {
    const active = members.filter((t) => !t.departed);

    return stripWeeks.value.map((week, i) => ({ week, index: stripStart.value + i, ...aggregateWeek(active, stripStart.value + i, week, week === props.currentWeek) }));
}

function stripLabel(trooper) {
    const cells = cellsFor(trooper);
    const signed = cells.filter((c) => c.rc === 'ok' || c.rc === 'cn' || c.rc === 'mr').length;
    const attended = cells.filter((c) => ['ok', 'mk'].includes(c.sp)).length;
    const missed = cells.filter((c) => c.sp === 'bad').length;

    return `Last 8 weeks: roll call signed ${signed} of 8 weeks, section practice attended ${attended}${missed ? `, ${missed} missed` : ''}.`;
}

// Floating tip content, keyed `m:<trooperId>:<weekIndex>` or `s:<sectionId>:<weekIndex>`.
const byId = computed(() => new Map(props.troopers.map((t) => [t.id, t])));
const unitById = computed(() => new Map(props.units.map((u) => [u.id, u])));
function resolveTip(key) {
    const [kind, id, indexText] = key.split(':');
    const index = Number(indexText);
    const week = props.weeks[index];
    const current = week === props.currentWeek;
    const title = `${weekLabel(week)}${current ? ' (this week)' : ''}`;
    if (kind === 'm') {
        const trooper = byId.value.get(Number(id));
        const mark = trooper.weeks[index];
        const cell = weekCell(mark, week, { current, joined: trooper.joined, excused: trooper.excusedWeeks?.[week] ?? null });

        return { title, rows: [['Roll call', RC_STATES[cell.rc]], ['SP', SP_STATES[cell.sp], sourceLabel(mark)]] };
    }
    const unit = unitById.value.get(Number(id));
    const agg = aggregateWeek((membersBySection.value.get(unit.id) ?? []).filter((t) => !t.departed), index, week, current);

    return {
        title: `${unit.callsign ?? unit.name}, ${title.toLowerCase()}`,
        rows: [
            ['Roll call', agg.due ? `${agg.signed} of ${agg.due} signed` : RC_STATES[agg.rc] ?? 'n/a'],
            ['SP', agg.marked ? `${agg.attended} of ${agg.marked} attended` : SP_STATES[agg.sp] ?? 'n/a'],
        ],
    };
}

const toneClass = { bad: 'text-destructive', warn: 'text-warning', ok: '', muted: 'text-muted-foreground' };

const table = ref(null);
const search = ref(null);
function onRowKey(event, trooper) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        emit('open', trooper.id);

        return;
    }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
    event.preventDefault();
    const all = [...table.value.querySelectorAll('tr[data-member]')];
    const at = all.indexOf(event.currentTarget);
    all[at + (event.key === 'ArrowDown' ? 1 : -1)]?.focus();
}

// On the roster `/` filters this table, as it always has; the shell's trooper search takes the key back elsewhere.
useSlashClaim(() => search.value?.focus());

function clearAll() {
    query.value = '';
    filter.value = 'all';
}
</script>

<template>
    <section class="overflow-clip rounded-[10px] border border-border bg-card" aria-label="Order of battle" data-testid="roster-orbat">
        <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
            <div class="relative w-full sm:w-72">
                <PhMagnifyingGlass class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <label for="roster-q" class="sr-only">Filter roster by name, rank or billet</label>
                <input
                    id="roster-q"
                    ref="search"
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Filter by name, rank or billet"
                    class="h-8 w-full rounded-md border border-input bg-card px-8 text-[13px] placeholder:text-muted-foreground focus-visible:border-transparent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring [&::-webkit-search-cancel-button]:hidden"
                    data-testid="roster-search"
                    @keydown.esc="query = ''"
                />
                <kbd v-if="!query" class="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm border border-border bg-muted px-1 font-mono text-[11px] text-muted-foreground" aria-hidden="true">/</kbd>
                <Button v-else variant="ghost" size="icon-sm" class="absolute top-1/2 right-0.5 size-7 -translate-y-1/2" aria-label="Clear filter" @click="query = ''">
                    <PhX />
                </Button>
            </div>
            <!-- The page can put a control before the chips, such as the way back up to the unit above. -->
            <slot name="before-filters" />
            <FilterChips v-model="filter" :troopers="troopers" :current-week="currentWeek" />
            <div class="ml-auto flex items-center gap-1">
                <Button v-if="sort" variant="ghost" size="sm" data-testid="sort-reset" @click="resetSort">Billet order</Button>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="searching || loading"
                    :title="searching ? 'Sections with matches stay open while a filter is active' : undefined"
                    @click="expandAll"
                >
                    <PhArrowsOutLineVertical />Expand all
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="searching || loading"
                    :title="searching ? 'Sections with matches stay open while a filter is active' : undefined"
                    @click="collapseAll"
                >
                    <PhArrowsInLineVertical />Collapse all
                </Button>
            </div>
        </div>

        <CellLegend class="border-b border-border" />

        <div ref="table" v-sticky-head class="overflow-x-auto">
            <table class="orbat w-full min-w-[1000px] border-separate border-spacing-0 text-[13px]" aria-describedby="orbat-showing" :aria-busy="loading || undefined">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-[4]" :aria-sort="ariaSort('rank', 'name')" data-testid="th-trooper">
                            <span class="inline-flex items-center gap-2">
                                <button type="button" class="sort-btn" data-testid="sort-rank" title="Sort by rank, most senior first" @click="sortBy('rank')">
                                    {{ ORBAT_SORTS.rank.label }}<component :is="sortIcon('rank')" class="size-3" aria-hidden="true" />
                                </button>
                                <button type="button" class="sort-btn" data-testid="sort-name" title="Sort by name" @click="sortBy('name')">
                                    {{ ORBAT_SORTS.name.label }}<component :is="sortIcon('name')" class="size-3" aria-hidden="true" />
                                </button>
                            </span>
                        </th>
                        <th>Billet</th>
                        <th v-for="key in ['tis', 'tig']" :key="key" class="text-right" :aria-sort="ariaSort(key)" :data-testid="`th-${key}`">
                            <button type="button" class="sort-btn ml-auto" :data-testid="`sort-${key}`" :title="`Sort by ${ORBAT_SORTS[key].label.toLowerCase()}`" @click="sortBy(key)">
                                <abbr :title="ORBAT_SORTS[key].label" class="no-underline">{{ key.toUpperCase() }}</abbr><component :is="sortIcon(key)" class="size-3" aria-hidden="true" />
                            </button>
                        </th>
                        <th>
                            <span class="block">Last 8 weeks</span>
                            <!-- The first and last week's start dates only: their full ranges would run together in the strip's width. -->
                            <span class="mt-0.5 flex w-[104px] justify-between gap-2 text-[10px] font-normal tabular-nums" data-testid="strip-ends">
                                <span>{{ weekStartTick(stripWeeks[0]) }}</span><span>{{ weekStartTick(stripWeeks[STRIP - 1]) }}</span>
                            </span>
                        </th>
                        <th v-for="key in ['rc', 'sp']" :key="key" class="text-right" :aria-sort="ariaSort(key)" :data-testid="`th-${key}`">
                            <button type="button" class="sort-btn ml-auto" :data-testid="`sort-${key}`" :title="`Sort by ${ORBAT_SORTS[key].label}, lowest first`" @click="sortBy(key)">
                                {{ ORBAT_SORTS[key].label }}<component :is="sortIcon(key)" class="size-3" aria-hidden="true" />
                            </button>
                        </th>
                        <th>Flags</th>
                    </tr>
                </thead>

                <tbody v-if="loading" aria-hidden="true">
                    <tr v-for="n in 9" :key="n">
                        <td class="sticky left-0"><div class="flex items-center gap-2.5" :class="n % 5 && 'pl-6'"><Skeleton class="h-[22px] w-12" /><Skeleton class="h-3.5 w-24" /></div></td>
                        <td><Skeleton class="h-3.5 w-32" /></td>
                        <td><Skeleton class="ml-auto h-3.5 w-9" /></td>
                        <td><Skeleton class="ml-auto h-3.5 w-9" /></td>
                        <td><Skeleton class="h-[18px] w-[104px]" /></td>
                        <td><Skeleton class="ml-auto h-3.5 w-9" /></td>
                        <td><Skeleton class="ml-auto h-3.5 w-9" /></td>
                        <td><Skeleton class="h-5 w-16" /></td>
                    </tr>
                </tbody>

                <tbody v-else-if="!shownCount">
                    <tr>
                        <td colspan="8" class="py-14">
                            <EmptyState :icon="PhMagnifyingGlass" :title="query.trim() ? `No troopers match &quot;${query.trim()}&quot;` : 'No troopers match this filter'" class="sticky left-0 w-[min(100%,calc(100vw-3rem))]">
                                Check the spelling, or try a rank like SGT or a billet like medic.
                            </EmptyState>
                            <div class="mt-3 flex justify-center"><Button variant="outline" size="sm" @click="clearAll">Clear filter</Button></div>
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <template v-for="row in rows" :key="`${row.kind}-${row.unit?.id ?? row.trooper.id}`">
                        <tr v-if="row.kind === 'group'" class="group-row" :data-testid="`group-${row.unit.id}`">
                            <td colspan="8">
                                <div class="sticky left-2.5 inline-flex flex-nowrap items-center gap-7" :style="{ paddingLeft: `${Math.max(row.depth - 1, 0) * 1.25}rem` }">
                                    <div class="flex min-w-[17rem] items-center gap-2.5">
                                        <!-- The company's or platoon's guidon, or the nearest one above; the name beside it says whose. -->
                                        <Guidon v-if="['company', 'platoon'].includes(row.unit.type)" :design="row.unit.headerGuidon" :height="12" />
                                        <span>
                                            <span class="block text-[13px] leading-tight font-semibold">
                                                {{ row.unit.name }} <span class="font-normal text-muted-foreground">{{ row.unit.callsign }}</span>
                                                <span v-if="row.unit.label" class="ml-1 rounded-sm border border-border px-1 text-[10px] font-semibold text-muted-foreground" :data-testid="`label-${row.unit.id}`">{{ row.unit.label }}</span>
                                            </span>
                                        </span>
                                    </div>
                                    <dl class="flex items-center gap-6">
                                        <div title="Everyone serving in the unit and under it, HQ staff included. New Recruits, ELOA and Reservists count on their own group."><dt class="text-[11px] leading-tight text-muted-foreground">Headcount</dt><dd class="text-[13px] leading-tight font-semibold tabular-nums">{{ row.unit.kpis.strength }}</dd></div>
                                        <div><dt class="text-[11px] leading-tight text-muted-foreground">RC 30d</dt><dd class="text-[13px] leading-tight font-semibold tabular-nums" :class="toneClass[percentTone(row.unit.kpis.rc)]">{{ percentLabel(row.unit.kpis.rc) }}</dd></div>
                                        <div><dt class="text-[11px] leading-tight text-muted-foreground">SP 30d</dt><dd class="text-[13px] leading-tight font-semibold tabular-nums" :class="toneClass[percentTone(row.unit.kpis.sp)]">{{ percentLabel(row.unit.kpis.sp) }}</dd></div>
                                    </dl>
                                    <span class="inline-flex gap-1">
                                        <Badge v-if="row.counts.risk" variant="danger" class="tabular-nums">{{ row.counts.risk }} at-risk</Badge>
                                        <Badge v-if="row.counts.loa" variant="outline" class="tabular-nums"><PhLockSimple />{{ row.counts.loa }} LOA</Badge>
                                        <Badge v-if="row.counts.fresh" variant="info" class="tabular-nums">{{ row.counts.fresh }} joined recently</Badge>
                                        <span v-if="!row.counts.risk && !row.counts.loa && !row.counts.fresh" class="text-xs text-muted-foreground">No flags</span>
                                    </span>
                                </div>
                            </td>
                        </tr>

                        <tr v-else-if="row.kind === 'section'" class="section-row" :data-testid="`section-${row.unit.id}`">
                            <td class="sticky left-0">
                                <button
                                    type="button"
                                    class="flex items-center gap-2 rounded-sm py-0.5 pr-1 text-left"
                                    :aria-expanded="row.open"
                                    :aria-disabled="searching || undefined"
                                    :title="searching ? 'Clear the filter to fold sections' : undefined"
                                    :data-testid="`toggle-${row.unit.id}`"
                                    @click="toggle(row.unit.id)"
                                >
                                    <PhCaretRight class="size-3.5 text-muted-foreground transition-transform motion-reduce:transition-none" :class="row.open && 'rotate-90'" aria-hidden="true" />
                                    <span>
                                        <span class="block leading-tight font-semibold">
                                            <template v-for="(part, i) in highlightParts(row.unit.callsign ?? row.unit.name, query)" :key="i"><mark v-if="part.hit" class="hl">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                                        </span>
                                        <span class="mt-0.5 block text-xs leading-tight text-muted-foreground">
                                            {{ row.unit.name }}<template v-if="row.unit.leader">, {{ row.unit.leader.displayName }}</template>
                                        </span>
                                    </span>
                                </button>
                            </td>
                            <td class="text-xs text-muted-foreground tabular-nums">{{ searching ? `${row.shown} of ${row.total}` : row.total }} troopers</td>
                            <td></td>
                            <td></td>
                            <td>
                                <span class="inline-flex gap-[3px] rounded-sm px-[3px] py-0.5" role="img" :aria-label="`${row.unit.callsign ?? row.unit.name}, last 8 weeks by section`">
                                    <span v-for="cell in aggregateCells(row.members)" :key="cell.week" :data-tip="`s:${row.unit.id}:${cell.index}`" :class="cell.week === currentWeek && 'is-now'" class="strip-wk">
                                        <WeekCell :rc="cell.rc" :sp="cell.sp" size="sm" />
                                    </span>
                                </span>
                            </td>
                            <td class="text-right font-semibold tabular-nums" :class="toneClass[percentTone(row.unit.kpis.rc)]">{{ percentLabel(row.unit.kpis.rc) }}</td>
                            <td class="text-right font-semibold tabular-nums" :class="toneClass[percentTone(row.unit.kpis.sp)]">{{ percentLabel(row.unit.kpis.sp) }}</td>
                            <td>
                                <span class="inline-flex gap-1">
                                    <Badge v-if="row.counts.risk" variant="danger" class="tabular-nums">{{ row.counts.risk }} at-risk</Badge>
                                    <Badge v-if="row.counts.loa" variant="outline" class="tabular-nums"><PhLockSimple />{{ row.counts.loa }} LOA</Badge>
                                    <Badge v-if="row.counts.fresh" variant="info" class="tabular-nums">{{ row.counts.fresh }} joined recently</Badge>
                                    <span v-if="!row.counts.risk && !row.counts.loa && !row.counts.fresh" class="text-xs text-muted-foreground">None</span>
                                </span>
                            </td>
                        </tr>

                        <tr
                            v-else
                            class="member-row cursor-pointer"
                            :class="[row.pinned && 'is-pin', row.pinnedLast && 'is-pin-last', (row.trooper.kpis?.onLoa || row.trooper.departed) && 'is-muted']"
                            tabindex="0"
                            data-member
                            :data-testid="`trooper-${row.trooper.id}`"
                            :aria-label="`${row.trooper.displayName}, ${row.trooper.billet ?? 'no billet on record'}${row.trooper.kpis?.atRisk ? ', at-risk' : ''}. Open details.`"
                            @click="emit('open', row.trooper.id)"
                            @keydown="onRowKey($event, row.trooper)"
                        >
                            <td class="sticky left-0">
                                <div class="flex items-center gap-2.5" :class="root.type !== 'section' && 'pl-6'">
                                    <RankBadge :rank="row.trooper.rank" :title="row.trooper.rankFull" />
                                    <span class="nm font-medium">
                                        <template v-for="(part, i) in highlightParts(displayName(row.trooper), query)" :key="i"><mark v-if="part.hit" class="hl">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="inline-flex items-center gap-1.5 text-muted-foreground">
                                    <span v-if="row.trooper.role" class="inline-flex h-[1.0625rem] items-center rounded-sm bg-foreground px-1.5 text-[10px] font-semibold tracking-wide text-background">{{ row.trooper.role }}</span>
                                    <span><template v-for="(part, i) in highlightParts(row.trooper.billet ?? 'No billet on record', query)" :key="i"><mark v-if="part.hit" class="hl">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                                    <span v-if="row.trooper.was" class="font-mono text-[11px]" :data-testid="`was-${row.trooper.id}`">was: {{ row.trooper.was }}</span>
                                </span>
                            </td>
                            <td class="text-right text-muted-foreground tabular-nums" :title="row.trooper.joined ? `Joined ${forumDate(row.trooper.joined)}` : undefined" data-testid="tis">{{ tenure(row.trooper, row.trooper.joined) }}</td>
                            <td class="text-right text-muted-foreground tabular-nums" :title="row.trooper.tig ? `In grade since ${forumDate(row.trooper.tig)}` : undefined" data-testid="tig">{{ tenure(row.trooper, row.trooper.tig) }}</td>
                            <td>
                                <span class="inline-flex gap-[3px] rounded-sm px-[3px] py-0.5" role="img" :aria-label="stripLabel(row.trooper)">
                                    <span
                                        v-for="cell in cellsFor(row.trooper)"
                                        :key="cell.week"
                                        :data-tip="`m:${row.trooper.id}:${cell.index}`"
                                        :class="cell.week === currentWeek && 'is-now'"
                                        class="strip-wk"
                                    >
                                        <WeekCell :rc="cell.rc" :sp="cell.sp" size="sm" />
                                    </span>
                                </span>
                            </td>
                            <td class="text-right tabular-nums" :class="toneClass[percentTone(row.trooper.kpis?.rc)]"><template v-if="row.trooper.kpis">{{ percentLabel(row.trooper.kpis.rc) }}</template><NoValue v-else /></td>
                            <td class="text-right tabular-nums" :class="toneClass[percentTone(row.trooper.kpis?.sp)]"><template v-if="row.trooper.kpis">{{ percentLabel(row.trooper.kpis.sp) }}</template><NoValue v-else /></td>
                            <td>
                                <span class="inline-flex gap-1">
                                    <Badge v-if="row.trooper.kpis?.atRisk" variant="danger" data-testid="flag-at-risk"><PhWarning />At-risk, {{ row.trooper.kpis.missed }} missed</Badge>
                                    <Badge v-if="row.trooper.kpis?.onLoa" variant="outline"><PhLockSimple />LOA</Badge>
                                    <Badge v-if="!row.trooper.departed && isNewRecruit(row.trooper, currentWeek)" variant="info" :title="`Joined ${forumDate(row.trooper.joined)}`"><PhUserPlus />Joined recently</Badge>
                                    <Badge v-if="row.trooper.departed" variant="outline"><PhSignOut />Departed</Badge>
                                </span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
            <span id="orbat-showing" class="tabular-nums" aria-live="polite">
                <template v-if="loading">Loading roster</template>
                <template v-else-if="searching">{{ shownCount }} of {{ scopedCount }} troopers match<template v-if="sectionsShown"> in {{ sectionsShown }} section{{ sectionsShown === 1 ? '' : 's' }}</template></template>
                <template v-else>Showing {{ shownCount }} trooper{{ shownCount === 1 ? '' : 's' }}</template>
            </span>
        </div>

        <FloatingTip :container="table" :resolve="resolveTip" />
    </section>
</template>

<style scoped>
.orbat th {
    padding: 0.5rem 0.625rem;
    border-bottom: 1px solid var(--border);
    background: var(--card);
    color: var(--muted-foreground);
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1.25;
    text-align: left;
    vertical-align: bottom;
    white-space: nowrap;
}
.orbat .sort-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 0.25rem;
    font-weight: inherit;
    white-space: nowrap;
}
.orbat .sort-btn:hover {
    color: var(--foreground);
}
.orbat .sort-btn:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.orbat th[aria-sort] {
    color: var(--foreground);
}
.orbat td {
    padding: 0.4375rem 0.625rem;
    border-bottom: 1px solid color-mix(in oklab, var(--border) 60%, transparent);
    background: var(--card);
    white-space: nowrap;
    vertical-align: middle;
}
.orbat .member-row:hover td,
.orbat .member-row:focus-visible td {
    background: color-mix(in oklab, var(--accent) 70%, var(--card));
}
.orbat .member-row:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: -2px;
}
.orbat .member-row.is-pin td {
    background: color-mix(in oklab, var(--muted) 50%, var(--card));
}
.orbat .member-row.is-pin-last td {
    border-bottom-color: var(--border);
}
.orbat .member-row.is-muted .nm {
    color: var(--muted-foreground);
}
.orbat .group-row td {
    padding: 0.625rem;
    background: var(--muted);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.orbat tbody tr:first-child.group-row td {
    border-top: 0;
}
.orbat .section-row td {
    border-bottom: 1px solid var(--border);
}
.orbat .section-row:hover td {
    background: color-mix(in oklab, var(--accent) 45%, var(--card));
}
.orbat .strip-wk {
    position: relative;
    display: inline-flex;
}
.orbat .strip-wk.is-now::before {
    content: '';
    position: absolute;
    inset: -3px -2px;
    border-radius: 3px;
    background: color-mix(in oklab, var(--primary) 16%, transparent);
    box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary) 50%, transparent);
}
.orbat mark.hl,
:deep(mark.hl) {
    background: color-mix(in oklab, var(--primary) 38%, transparent);
    color: inherit;
    border-radius: 2px;
    padding: 0 1px;
    margin: 0 -1px;
}
</style>
