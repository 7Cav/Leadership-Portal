<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Card } from '@/components/ui/card';
import StrengthChart from '@/components/roster/StrengthChart.vue';

// The roster's Strength tab: the unit's headcount and in roll call per Cav week over a chosen range, the change over it,
// and its children's headcounts as small multiples on one scale. A battalion's companies also list their platoons as
// sparkline rows under the company's chart, all on one shared scale. Read only; every number comes from StrengthTrends.
const props = defineProps({
    unit: { type: Object, required: true },
    // { range, weeks, change, children }, or null while it loads.
    trends: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});
const emit = defineEmits(['range']);

const RANGES = [
    { value: '12', label: '12 weeks' },
    { value: '26', label: '26 weeks' },
    { value: '52', label: '52 weeks' },
    { value: 'all', label: 'All' },
];

const designation = computed(() => props.unit.callsign ?? props.unit.name);
const children = computed(() => props.trends?.children ?? []);

const signed = (v) => (v === null ? 'Not known' : v > 0 ? `+${v}` : String(v));
const change = (series) => {
    const known = series.points.map((p) => p.headcount).filter((v) => v !== null);

    return known.length < 2 ? null : known[known.length - 1] - known[0];
};
const latest = (series) => [...series.points].reverse().find((p) => p.headcount !== null)?.headcount ?? null;

const childMax = computed(() => Math.max(1, ...children.value.flatMap((c) => c.points.map((p) => p.headcount ?? 0))));
const subMax = computed(() =>
    Math.max(1, ...children.value.flatMap((c) => (c.children ?? []).flatMap((g) => g.points.map((p) => p.headcount ?? 0)))),
);

// A sparkline for one platoon row: no axes, one path per unbroken run of known weeks, on the shared platoon scale.
const SPARK = { w: 120, h: 20 };
function spark(points) {
    const span = Math.max(points.length - 1, 1);
    let d = '';
    let pen = false;
    points.forEach((p, i) => {
        if (p.headcount === null) {
            pen = false;

            return;
        }
        const x = (i * SPARK.w) / span;
        const y = SPARK.h - 2 - (p.headcount / subMax.value) * (SPARK.h - 4);
        d += `${pen ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`;
        pen = true;
    });

    return d;
}

const tabHref = (id) => `/roster/${id}?view=strength&range=${props.trends?.range ?? '12'}`;
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="roster-strength" :aria-busy="loading || !trends">
        <nav aria-label="Range" class="flex flex-wrap gap-1.5" data-testid="strength-ranges">
            <button
                v-for="r in RANGES"
                :key="r.value"
                type="button"
                class="rounded-md border px-2.5 py-1 text-[13px]"
                :class="r.value === trends?.range ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted'"
                :aria-pressed="r.value === trends?.range"
                :data-range="r.value"
                :disabled="loading"
                @click="emit('range', r.value)"
            >
                {{ r.label }}
            </button>
        </nav>

        <Card v-if="!trends" class="p-4">
            <p class="py-10 text-center text-xs text-muted-foreground" data-testid="strength-loading">Loading strength.</p>
        </Card>

        <template v-else>
            <Card class="p-4">
                <dl class="mb-4 flex flex-wrap gap-x-8 gap-y-2 text-[13px]" data-testid="strength-change">
                    <div>
                        <dt class="text-muted-foreground">Headcount change</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ signed(trends.change.headcount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">In roll call change</dt>
                        <dd class="text-lg font-semibold tabular-nums">{{ signed(trends.change.strength) }}</dd>
                    </div>
                </dl>
                <StrengthChart :points="trends.weeks" :label="`${designation} headcount and in roll call by week`" />
                <p class="mt-3 text-xs text-muted-foreground">
                    Sent weeks show the numbers frozen in the report. Other weeks are rebuilt from where each trooper was
                    placed, from late October 2026 on. Weeks with neither are left blank.
                </p>
            </Card>

            <section v-if="children.length" aria-labelledby="strength-children">
                <h2 id="strength-children" class="mb-2 text-[15px] font-semibold">Headcount by unit</h2>
                <div class="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="strength-children">
                    <Card v-for="child in children" :key="child.id" class="min-w-0 p-3" :data-unit="child.id">
                        <div class="mb-1 flex items-baseline justify-between gap-2 text-[13px]">
                            <Link :href="tabHref(child.id)" class="font-medium hover:underline">{{ child.designation }}</Link>
                            <span class="tabular-nums text-muted-foreground">{{ signed(change(child)) }}</span>
                        </div>
                        <StrengthChart
                            :points="child.points"
                            :series="['headcount']"
                            :height="96"
                            :max="childMax"
                            compact
                            :label="`${child.designation} headcount by week`"
                        />
                        <ul
                            v-if="child.children?.length"
                            class="mt-2 divide-y divide-border border-t border-border"
                            :aria-label="`Platoons of ${child.designation}`"
                            data-testid="strength-subunits"
                        >
                            <li
                                v-for="sub in child.children"
                                :key="sub.id"
                                class="grid grid-cols-[minmax(0,1fr)_auto_2.5rem] items-center gap-2 py-1 text-xs"
                                :data-subunit="sub.id"
                            >
                                <Link :href="tabHref(sub.id)" class="truncate hover:underline">{{ sub.designation }}</Link>
                                <svg
                                    :width="SPARK.w"
                                    :height="SPARK.h"
                                    :viewBox="`0 0 ${SPARK.w} ${SPARK.h}`"
                                    class="block w-[clamp(64px,22vw,120px)] overflow-visible"
                                    preserveAspectRatio="none"
                                    role="img"
                                    :aria-label="`${sub.designation} headcount by week, now ${latest(sub) ?? 'not known'}`"
                                >
                                    <path
                                        :d="spark(sub.points)"
                                        fill="none"
                                        style="stroke: var(--chart-1)"
                                        stroke-width="1.5"
                                        stroke-linejoin="round"
                                        stroke-linecap="round"
                                        vector-effect="non-scaling-stroke"
                                        data-testid="strength-spark"
                                    />
                                </svg>
                                <span class="text-right tabular-nums text-muted-foreground" title="Change over the range">{{
                                    change(sub) === null ? '' : signed(change(sub))
                                }}</span>
                            </li>
                        </ul>
                    </Card>
                </div>
            </section>
        </template>
    </div>
</template>
