<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import NoValue from '@/components/NoValue.vue';
import Sparkline from '@/components/reports/Sparkline.vue';
import { Card } from '@/components/ui/card';
import { weekLabel } from '@/lib/forumDate';

// How an HQ's unit stood in one Cav week, beside its staff reports: SP and roll call over the KPI window (the numbers
// Home and the roster show), headcount against how many are in roll call, at risk, and how many of the HQ's staff kept
// to the one practice rule, each with the last 8 weeks as a sparkline. The server computes all of it.
const props = defineProps({
    // `{ unit: { id, label }, week, sp, rc, headcount, inRollcall, atRisk, staff: { met, total },
    // trend: [{ week, sp, rc, headcount, inRollcall, atRisk }], hqs?: [{ id, label }] }`. `sp` and `rc` are 0 to 1 or null.
    health: { type: Object, required: true },
    // On the overview: the link to show another HQ's health, given its id. Absent on a single report.
    hqHref: { type: Function, default: null },
});

const pct = (v) => (v === null || v === undefined ? null : Math.round(v * 100));
const trend = computed(() => props.health.trend ?? []);
const first = computed(() => trend.value[0]?.week);
const span = computed(() => (first.value ? `${trend.value.length} weeks from the week ending ${weekLabel(first.value)}` : ''));

const tiles = computed(() => [
    {
        key: 'sp',
        label: 'SP attendance',
        value: pct(props.health.sp),
        unit: '%',
        series: trend.value.map((p) => pct(p.sp)),
        domain: [0, 100],
        color: 'var(--chart-2)',
    },
    {
        key: 'rc',
        label: 'Roll call',
        value: pct(props.health.rc),
        unit: '%',
        series: trend.value.map((p) => pct(p.rc)),
        domain: [0, 100],
        color: 'var(--chart-1)',
    },
    {
        key: 'strength',
        label: 'In roll call',
        value: props.health.inRollcall,
        unit: props.health.headcount === null || props.health.headcount === undefined ? '' : ` of ${props.health.headcount}`,
        series: trend.value.map((p) => p.inRollcall),
        color: 'var(--chart-3)',
    },
    {
        key: 'atRisk',
        label: 'At risk',
        value: props.health.atRisk,
        unit: '',
        series: trend.value.map((p) => p.atRisk),
        color: 'var(--chart-5)',
        warn: props.health.atRisk > 0,
    },
]);

const staffMet = computed(() => props.health.staff ?? { met: 0, total: 0 });

function spoken(tile) {
    const known = tile.series.map((v, i) => (v === null || v === undefined ? null : `${weekLabel(trend.value[i].week)} ${v}${tile.unit === '%' ? '%' : ''}`));

    return `${tile.label} by week: ${known.map((v) => v ?? 'no data').join(', ')}`;
}
</script>

<template>
    <Card class="gap-0 py-0 text-[13px]" data-testid="staff-hq-health">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-2.5">
            <h2 class="text-sm font-semibold">{{ health.unit.label }} health</h2>
            <p class="text-xs text-muted-foreground">{{ span }}</p>
            <nav v-if="hqHref && health.hqs?.length > 1" class="flex flex-wrap gap-1 sm:ml-auto" aria-label="Show the health of" data-testid="health-hqs">
                <Link
                    v-for="hq in health.hqs"
                    :key="hq.id"
                    :href="hqHref(hq.id)"
                    preserve-scroll
                    class="rounded-md px-2 py-0.5 font-mono text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="hq.id === health.unit.id ? 'bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground'"
                    :aria-current="hq.id === health.unit.id ? 'true' : undefined"
                >
                    {{ hq.label }}
                </Link>
            </nav>
        </div>
        <dl class="grid grid-cols-2 sm:grid-cols-5">
            <div v-for="tile in tiles" :key="tile.key" class="flex flex-col gap-1 border-b px-4 py-3 sm:border-r sm:border-b-0" :data-testid="`health-${tile.key}`">
                <dt class="text-xs text-muted-foreground">{{ tile.label }}</dt>
                <dd class="flex items-end justify-between gap-2">
                    <span class="text-lg font-semibold tabular-nums" :class="{ 'text-warning': tile.warn }">
                        <NoValue v-if="tile.value === null || tile.value === undefined" />
                        <template v-else>{{ tile.value }}<span class="text-xs font-normal text-muted-foreground">{{ tile.unit }}</span></template>
                    </span>
                    <Sparkline :values="tile.series" :domain="tile.domain" :color="tile.color" :label="spoken(tile)" />
                </dd>
            </div>
            <div class="col-span-2 flex flex-col gap-1 px-4 py-3 sm:col-span-1" data-testid="health-staff">
                <dt class="text-xs text-muted-foreground">Staff with 1 SP</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ staffMet.met }}<span class="text-xs font-normal text-muted-foreground"> of {{ staffMet.total }}</span>
                </dd>
            </div>
        </dl>
    </Card>
</template>
