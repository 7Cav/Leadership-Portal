<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhCaretDown, PhCaretRight, PhCaretUp, PhCaretUpDown, PhUsersThree } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { percent } from '@/lib/report';
import EmptyState from '@/components/EmptyState.vue';

// The subordinate-units table (Story 5.11): each direct subordinate of the viewer's scope with its
// KPIs and send status, sortable, each row drilling through to the unit's report. Values and the
// sent/draft/holding semantics come from the server (the shared KPI pieces and the roll-up, AD-13,
// AD-21); nothing is derived here beyond turning a ratio into a percent.
const props = defineProps({
    units: { type: Array, required: true },
});

const COLUMNS = [
    { key: 'designation', label: 'Unit', numeric: false },
    { key: 'strength', label: 'Strength', numeric: true },
    { key: 'rc', label: 'RC %', numeric: true },
    { key: 'sp', label: 'SP %', numeric: true },
    { key: 'atRisk', label: 'At-risk', numeric: true },
    { key: 'onLoa', label: 'On LOA', numeric: true },
    { key: 'status', label: 'Report', numeric: false },
];

// What the Report column says: a unit still waiting on its children is "Holding" however its own
// report stands, matching the roll-up navigator.
function statusOf(unit) {
    if (unit.holding) return { key: 'holding', label: 'Holding', variant: 'warning', rank: 0 };
    if (unit.status === 'draft') return { key: 'draft', label: 'Draft', variant: 'warning', rank: 1 };
    if (unit.status === 'not_started') return { key: 'not_started', label: 'Not started', variant: 'outline', rank: 2 };

    return { key: 'sent', label: 'Sent', variant: 'success', rank: 3 };
}

const rows = computed(() =>
    props.units.map((unit) => ({
        unit,
        rc: percent(unit.kpis.rc),
        sp: percent(unit.kpis.sp),
        status: statusOf(unit),
    })),
);

const sort = ref({ key: null, dir: 1 });

function value(row, key) {
    if (key === 'designation') return row.unit.designation;
    if (key === 'rc' || key === 'sp') return row[key];
    if (key === 'status') return row.status.rank;

    return row.unit.kpis[key];
}

// A rate with nothing eligible has no value and sorts last either way, never as 0%.
const sorted = computed(() => {
    const { key, dir } = sort.value;
    if (!key) return rows.value;

    return [...rows.value].sort((a, b) => {
        const [x, y] = [value(a, key), value(b, key)];
        if (x === null || y === null) return (x === null) - (y === null);

        return (typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true }) : x - y) * dir;
    });
});

function sortBy(key) {
    sort.value = { key, dir: sort.value.key === key ? -sort.value.dir : 1 };
}

const ariaSort = (key) => (sort.value.key === key ? (sort.value.dir > 0 ? 'ascending' : 'descending') : 'none');
const sortIcon = (key) => (sort.value.key !== key ? PhCaretUpDown : sort.value.dir > 0 ? PhCaretUp : PhCaretDown);

// A single section is the viewer's own: there is nothing subordinate to compare.
const own = computed(() => props.units.length === 1 && props.units[0].type === 'section');
</script>

<template>
    <Card role="region" class="min-w-0 gap-0 py-0" aria-labelledby="units-title" data-testid="subordinate-units">
        <div class="flex items-baseline gap-2 px-4 pt-3.5">
            <h2 id="units-title" class="text-[15px] font-semibold">{{ own ? 'Your section' : 'Subordinate units' }}</h2>
            <span class="truncate text-xs text-muted-foreground">{{ own ? 'This week, your report' : 'This week, each unit against its report' }}</span>
        </div>

        <EmptyState v-if="!units.length" :icon="PhUsersThree" title="No units in your scope yet" class="py-10" data-testid="no-units">
            The units under your command, with their KPIs and report status, are compared here once one is assigned to you.
        </EmptyState>

        <div v-else class="mt-2 overflow-x-auto border-t border-border">
            <Table class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead
                            v-for="column in COLUMNS"
                            :key="column.key"
                            :aria-sort="ariaSort(column.key)"
                            class="h-auto py-2 text-xs font-medium text-muted-foreground"
                            :class="{ 'text-right': column.numeric }"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :data-sort="column.key"
                                @click="sortBy(column.key)"
                            >
                                {{ column.label }}
                                <component :is="sortIcon(column.key)" class="size-3" aria-hidden="true" />
                            </button>
                        </TableHead>
                        <TableHead class="w-8"><span class="sr-only">Open report</span></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in sorted" :key="row.unit.id" :data-unit="row.unit.id">
                        <TableCell class="min-w-[8rem] py-2">
                            <Link :href="row.unit.href" class="font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                {{ row.unit.designation }}
                            </Link>
                            <span class="block text-xs text-muted-foreground">{{ row.unit.name }}</span>
                        </TableCell>
                        <TableCell class="py-2 text-right tabular-nums">{{ row.unit.kpis.strength }}</TableCell>
                        <TableCell class="py-2 text-right tabular-nums">{{ row.rc === null ? '—' : `${row.rc}%` }}</TableCell>
                        <TableCell class="py-2 text-right tabular-nums">{{ row.sp === null ? '—' : `${row.sp}%` }}</TableCell>
                        <TableCell class="py-2 text-right tabular-nums" :class="row.unit.kpis.atRisk ? 'font-medium text-warning' : 'text-muted-foreground'">
                            {{ row.unit.kpis.atRisk }}
                        </TableCell>
                        <TableCell class="py-2 text-right tabular-nums" :class="{ 'text-muted-foreground': !row.unit.kpis.onLoa }">{{ row.unit.kpis.onLoa }}</TableCell>
                        <TableCell class="py-2">
                            <span class="inline-flex items-center gap-2 whitespace-nowrap">
                                <Badge :variant="row.status.variant" :data-status="row.status.key">{{ row.status.label }}</Badge>
                                <span v-if="row.unit.coverage" class="text-xs text-muted-foreground tabular-nums">
                                    {{ row.unit.coverage.sent }} of {{ row.unit.coverage.of }} sent
                                </span>
                            </span>
                        </TableCell>
                        <TableCell class="py-2 pr-3">
                            <Link :href="row.unit.href" class="text-muted-foreground hover:text-foreground" :aria-label="`Open the ${row.unit.designation} report`">
                                <PhCaretRight class="size-3.5" aria-hidden="true" />
                            </Link>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
        <p v-if="units.length" class="px-4 py-2.5 text-xs text-muted-foreground">
            Sent reports show their frozen figures; the rest are live. Select a unit to open its report.
        </p>
    </Card>
</template>
