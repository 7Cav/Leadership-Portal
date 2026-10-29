<script setup>
import { computed } from 'vue';
import { PhArrowsClockwise, PhWarning } from '@phosphor-icons/vue';
import { formatKpi } from '@/lib/report';
import { hasTrend, tileChange, tileSeries, TILES } from '@/lib/home';
import { forumDateTime } from '@/lib/forumDate';
import DeltaBadge from '@/components/reports/DeltaBadge.vue';
import LoaSyncNote from '@/components/LoaSyncNote.vue';
import TimeAgo from '@/components/TimeAgo.vue';
import Sparkline from './Sparkline.vue';

// The six canonical KPIs for the viewer's scope (Story 5.9, shell-f.html "Last 30 days"): live
// values from the shared KPI service, each with its trailing weeks of sent snapshots. Strength is
// synced from the milpac roster, and on LOA and at-risk move with leave synced from the forum, so both sources and
// their freshness sit beside the tiles (AD-20).
const props = defineProps({
    // `{ current, history, roster, loaSync }` from HomeController; `loaSync` is `LoaSyncState::toArray()`.
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
// The sentence around the sync time: `[before, after]`, or `[text]` when there is no time to show.
const rosterText = computed(() => {
    const at = roster.value.syncedAt;
    const source = roster.value.source;

    return {
        ok: [`Roster synced from ${source} `, ''],
        stale: [`Roster last synced from ${source} `, ', overdue'],
        partial: at ? ['Last roster sync was incomplete. Roll call figures are from ', ''] : ['Last roster sync was incomplete'],
        failed: at ? ['Last roster sync failed. Roll call figures are from ', ''] : ['The roster sync failed. Roll call figures are not yet available'],
        never: ['The roster has not synced yet'],
    }[roster.value.state];
});
const rosterAt = computed(() => (roster.value.syncedAt && rosterText.value.length === 2 ? forumDateTime(roster.value.syncedAt) : null));
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
                <!-- The headcount with HQ staff and groups, labelled apart from the rollcall figure above (#424). -->
                <dd v-if="tile.kpi.key === 'strength' && kpis.current.headcount != null" class="mt-0.5 text-[11px] text-muted-foreground" data-testid="headcount">
                    Headcount <b class="font-semibold text-foreground tabular-nums">{{ kpis.current.headcount }}</b>, with staff
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
            <span>{{ rosterText[0] }}<template v-if="rosterAt"><TimeAgo :at="roster.syncedAt">{{ rosterAt }}Z</TimeAgo>{{ rosterText[1] }}</template></span>
        </p>
        <LoaSyncNote v-if="kpis.loaSync" :sync="kpis.loaSync" class="mt-1" />
    </div>
</template>
