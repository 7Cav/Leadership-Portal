<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    PhArrowsInLineVertical,
    PhArrowsOutLineVertical,
    PhCaretRight,
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
import EmptyState from '@/components/EmptyState.vue';
import {
    aggregateWeek,
    compareBillet,
    displayName,
    FILTERS,
    highlightParts,
    isNewRecruit,
    matchesQuery,
    percentLabel,
    percentTone,
    RC_STATES,
    sourceLabel,
    SP_STATES,
    weekCell,
    weekLabel,
} from '@/lib/roster';
import CellLegend from './CellLegend.vue';
import FilterChips from './FilterChips.vue';
import FloatingTip from './FloatingTip.vue';
import RankBadge from './RankBadge.vue';
import WeekCell from './WeekCell.vue';

// Order of battle (binding mockup roster-s2.html): the subtree as platoon headers and collapsible
// sections, SL and ASL pinned on top, each trooper with an 8-week rollcall/SP strip, RC/SP 30d and
// flags. Searching highlights hits and opens every section that has one.
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
function sectionsUnder(unit) {
    if (unit.type === 'section') return [unit.id];

    return (childrenOf.value.get(unit.id) ?? []).flatMap(sectionsUnder);
}

const membersBySection = computed(() => {
    const map = new Map();
    for (const trooper of props.troopers) {
        if (!map.has(trooper.sectionId)) map.set(trooper.sectionId, []);
        map.get(trooper.sectionId).push(trooper);
    }
    for (const list of map.values()) list.sort(compareBillet);

    return map;
});

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
            continue;
        }

        const open = searching.value || !collapsed.value.has(unit.id);
        if (unit.id !== root.value.id) {
            out.push({ kind: 'section', unit, open, total: all.filter((t) => !t.departed).length, shown: shown.length, members: all, counts: counts(all) });
        }
        if (open || unit.id === root.value.id) {
            shown.forEach((trooper, index) => {
                const pinned = !!trooper.role;
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
            ...weekCell(trooper.weeks[index], week, { current: week === props.currentWeek, joined: trooper.joined }),
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
    const signed = cells.filter((c) => c.rc === 'ok' || c.rc === 'cn').length;
    const attended = cells.filter((c) => ['ok', 'mk', 'at'].includes(c.sp)).length;
    const missed = cells.filter((c) => c.sp === 'bad').length;

    return `Last 8 weeks: rollcall signed ${signed} of 8 weeks, section practice attended ${attended}${missed ? `, ${missed} missed` : ''}.`;
}

// Floating tip content, keyed `m:<trooperId>:<weekIndex>` or `s:<sectionId>:<weekIndex>`.
const byId = computed(() => new Map(props.troopers.map((t) => [t.id, t])));
const unitById = computed(() => new Map(props.units.map((u) => [u.id, u])));
function resolveTip(key) {
    const [kind, id, indexText] = key.split(':');
    const index = Number(indexText);
    const week = props.weeks[index];
    const current = week === props.currentWeek;
    const title = `Week of ${weekLabel(week)}${current ? ' (this week)' : ''}`;
    if (kind === 'm') {
        const trooper = byId.value.get(Number(id));
        const mark = trooper.weeks[index];
        const cell = weekCell(mark, week, { current, joined: trooper.joined });

        return { title, rows: [['Rollcall', RC_STATES[cell.rc]], ['SP', SP_STATES[cell.sp], sourceLabel(mark)]] };
    }
    const unit = unitById.value.get(Number(id));
    const agg = aggregateWeek((membersBySection.value.get(unit.id) ?? []).filter((t) => !t.departed), index, week, current);

    return {
        title: `${unit.callsign ?? unit.name}, ${title.toLowerCase()}`,
        rows: [
            ['Rollcall', agg.due ? `${agg.signed} of ${agg.due} signed` : RC_STATES[agg.rc] ?? 'n/a'],
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

// `/` jumps to the search, but not while an overlay (the trooper sheet, a picker, a popover) is
// open: focus would leave the overlay for a field behind it.
function onSlash(event) {
    if (event.key !== '/' || event.target.closest?.('input, textarea, [contenteditable]')) return;
    if (document.querySelector('[role="dialog"], [role="alertdialog"]')) return;
    event.preventDefault();
    search.value?.focus();
}
onMounted(() => document.addEventListener('keydown', onSlash));
onBeforeUnmount(() => document.removeEventListener('keydown', onSlash));

function clearAll() {
    query.value = '';
    filter.value = 'all';
}
</script>

<template>
    <section class="overflow-hidden rounded-[10px] border border-border bg-card" aria-label="Order of battle" data-testid="roster-orbat">
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
            <FilterChips v-model="filter" :troopers="troopers" :current-week="currentWeek" />
            <div class="ml-auto flex items-center gap-1">
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

        <div ref="table" class="overflow-x-auto">
            <table class="orbat w-full min-w-[880px] border-separate border-spacing-0 text-[13px]" aria-describedby="orbat-showing" :aria-busy="loading || undefined">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-[2]">Trooper</th>
                        <th>Billet</th>
                        <th>
                            <span class="block">Last 8 weeks</span>
                            <span class="mt-0.5 flex w-[104px] justify-between text-[10px] font-normal tabular-nums">
                                <span>{{ weekLabel(stripWeeks[0]).slice(0, 5) }}</span><span>{{ weekLabel(stripWeeks[STRIP - 1]).slice(0, 5) }}</span>
                            </span>
                        </th>
                        <th class="text-right">RC 30d</th>
                        <th class="text-right">SP 30d</th>
                        <th>Flags</th>
                    </tr>
                </thead>

                <tbody v-if="loading" aria-hidden="true">
                    <tr v-for="n in 9" :key="n">
                        <td class="sticky left-0"><div class="flex items-center gap-2.5" :class="n % 5 && 'pl-6'"><Skeleton class="h-[22px] w-12" /><Skeleton class="h-3.5 w-24" /></div></td>
                        <td><Skeleton class="h-3.5 w-32" /></td>
                        <td><Skeleton class="h-[18px] w-[104px]" /></td>
                        <td><Skeleton class="ml-auto h-3.5 w-9" /></td>
                        <td><Skeleton class="ml-auto h-3.5 w-9" /></td>
                        <td><Skeleton class="h-5 w-16" /></td>
                    </tr>
                </tbody>

                <tbody v-else-if="!shownCount">
                    <tr>
                        <td colspan="6" class="py-14">
                            <EmptyState :icon="PhMagnifyingGlass" :title="query.trim() ? `No troopers match “${query.trim()}”` : 'No troopers match this filter'" class="sticky left-0 w-[min(100%,calc(100vw-3rem))]">
                                Check the spelling, or try a rank like SGT or a billet like medic.
                            </EmptyState>
                            <div class="mt-3 flex justify-center"><Button variant="outline" size="sm" @click="clearAll">Clear filter</Button></div>
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <template v-for="row in rows" :key="`${row.kind}-${row.unit?.id ?? row.trooper.id}`">
                        <tr v-if="row.kind === 'group'" class="group-row" :data-testid="`group-${row.unit.id}`">
                            <td colspan="6">
                                <div class="sticky left-2.5 inline-flex flex-nowrap items-center gap-7" :style="{ paddingLeft: `${Math.max(row.depth - 1, 0) * 1.25}rem` }">
                                    <div class="flex min-w-[17rem] items-center gap-2.5">
                                        <span class="grid size-7 flex-none place-items-center rounded-md bg-foreground text-xs font-semibold text-background" aria-hidden="true">
                                            {{ (row.unit.callsign ?? row.unit.name).charAt(0) }}
                                        </span>
                                        <span>
                                            <span class="block text-[13px] leading-tight font-semibold">
                                                {{ row.unit.name }} <span class="font-normal text-muted-foreground">{{ row.unit.callsign }}</span>
                                            </span>
                                            <span class="mt-0.5 block text-xs leading-tight text-muted-foreground">
                                                {{ row.unit.leader ? `${row.unit.leader.displayName}` : 'No leader on the roster' }}
                                            </span>
                                        </span>
                                    </div>
                                    <dl class="flex items-center gap-6">
                                        <div><dt class="text-[11px] leading-tight text-muted-foreground">Strength</dt><dd class="text-[13px] leading-tight font-semibold tabular-nums">{{ row.unit.kpis.strength }}</dd></div>
                                        <div><dt class="text-[11px] leading-tight text-muted-foreground">RC 30d</dt><dd class="text-[13px] leading-tight font-semibold tabular-nums" :class="toneClass[percentTone(row.unit.kpis.rc)]">{{ percentLabel(row.unit.kpis.rc) }}</dd></div>
                                        <div><dt class="text-[11px] leading-tight text-muted-foreground">SP 30d</dt><dd class="text-[13px] leading-tight font-semibold tabular-nums" :class="toneClass[percentTone(row.unit.kpis.sp)]">{{ percentLabel(row.unit.kpis.sp) }}</dd></div>
                                    </dl>
                                    <span class="inline-flex gap-1">
                                        <Badge v-if="row.counts.risk" variant="danger" class="tabular-nums">{{ row.counts.risk }} at-risk</Badge>
                                        <Badge v-if="row.counts.loa" variant="outline" class="tabular-nums"><PhLockSimple />{{ row.counts.loa }} LOA</Badge>
                                        <Badge v-if="row.counts.fresh" variant="info" class="tabular-nums">{{ row.counts.fresh }} new</Badge>
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
                                    <Badge v-if="row.counts.fresh" variant="info" class="tabular-nums">{{ row.counts.fresh }} new</Badge>
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
                            :aria-label="`${row.trooper.displayName}, ${row.trooper.billet ?? 'no billet'}${row.trooper.kpis?.atRisk ? ', at risk' : ''}. Open details.`"
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
                                    <span><template v-for="(part, i) in highlightParts(row.trooper.billet ?? 'No billet', query)" :key="i"><mark v-if="part.hit" class="hl">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                                </span>
                            </td>
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
                            <td class="text-right tabular-nums" :class="toneClass[percentTone(row.trooper.kpis?.rc)]">{{ row.trooper.kpis ? percentLabel(row.trooper.kpis.rc) : '—' }}</td>
                            <td class="text-right tabular-nums" :class="toneClass[percentTone(row.trooper.kpis?.sp)]">{{ row.trooper.kpis ? percentLabel(row.trooper.kpis.sp) : '—' }}</td>
                            <td>
                                <span class="inline-flex gap-1">
                                    <Badge v-if="row.trooper.kpis?.atRisk" variant="danger" data-testid="flag-at-risk"><PhWarning />At-risk, {{ row.trooper.kpis.missed }} missed</Badge>
                                    <Badge v-if="row.trooper.kpis?.onLoa" variant="outline"><PhLockSimple />LOA</Badge>
                                    <Badge v-if="!row.trooper.departed && isNewRecruit(row.trooper, currentWeek)" variant="info" :title="`Joined ${row.trooper.joined}`"><PhUserPlus />New recruit</Badge>
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
                <template v-else>Showing {{ shownCount }} troopers</template>
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
