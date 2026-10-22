<script setup>
import { computed } from 'vue';
import { PhArrowsClockwise, PhWarning } from '@phosphor-icons/vue';
import { formatKpi } from '@/lib/report';
import { hasTrend, tileChange, tileSeries, TILES } from '@/lib/home';
import { forumDateTime } from '@/lib/forumDate';
import DeltaBadge from '@/components/reports/DeltaBadge.vue';
import Sparkline from './Sparkline.vue';

// The six canonical KPIs for the viewer's scope (Story 5.9, shell-f.html "Last 30 days"): live
// values from the shared KPI service, each with its trailing weeks of sent snapshots. Strength is
// synced from the milpac roster, so the source and its freshness sit beside the tiles (AD-20).
const props = defineProps({
    // `{ current, history, roster }` from HomeController.
    kpis: { type: Object, required: true },
});

const tiles = computed(() =>
    TILES.map((kpi) => {
        const series = tileSeries(kpi, props.kpis.current, props.kpis.history);

        return {
            kpi,
            value: formatKpi(props.kpis.current, kpi),
            format: (value) => (kpi.rate ? `${value}%` : String(value)),
            series,
            trend: hasTrend(series),
            change: tileChange(kpi, series),
        };
    }),
);

const roster = computed(() => props.kpis.roster);
const rosterProblem = computed(() => ['failed', 'partial', 'stale', 'never'].includes(roster.value.state));
const rosterText = computed(() => {
    const at = roster.value.syncedAt ? forumDateTime(roster.value.syncedAt) : null;
    const source = roster.value.source;

    return {
        ok: `Roster synced from ${source} ${at}Z`,
        stale: `Roster last synced from ${source} ${at}Z, overdue`,
        partial: `Last roster sync was incomplete. Strength is from ${at}Z`,
        failed: at ? `Last roster sync failed. Strength is from ${at}Z` : 'The roster sync failed. Strength is not yet available',
        never: 'The roster has not synced yet',
    }[roster.value.state];
});
</script>

<template>
    <div>
        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-3 xl:grid-cols-6" data-testid="kpi-tiles">
            <div v-for="tile in tiles" :key="tile.kpi.key" class="flex min-w-0 flex-col bg-card p-3.5" :data-kpi="tile.kpi.key">
                <dt class="text-xs text-muted-foreground">{{ tile.kpi.label }}</dt>
                <dd class="mt-0.5 flex items-baseline gap-2">
                    <span class="text-2xl font-semibold tracking-tight tabular-nums">{{ tile.value }}</span>
                    <DeltaBadge :change="tile.change" />
                </dd>
                <dd class="mt-2 min-h-7">
                    <Sparkline v-if="tile.trend" :series="tile.series" :label="tile.kpi.label" :format="tile.format" />
                    <span v-else class="text-[11px] text-muted-foreground" data-testid="building-history">Building history</span>
                </dd>
            </div>
        </dl>
        <p
            class="mt-2 flex items-center gap-1.5 text-xs"
            :class="rosterProblem ? 'text-warning' : 'text-muted-foreground'"
            :role="rosterProblem ? 'status' : undefined"
            data-testid="roster-freshness"
        >
            <component :is="rosterProblem ? PhWarning : PhArrowsClockwise" class="size-3.5 shrink-0" aria-hidden="true" />
            {{ rosterText }}
        </p>
    </div>
</template>
