<script setup>
import { computed, ref, useTemplateRef } from 'vue';
import { useElementSize } from '@vueuse/core';
import { weekLabel, weekTick } from '@/lib/forumDate';

// A unit's headcount and in roll call per Cav week, drawn like the report's TrendChart: one count axis from 0, two
// lines whose markers differ in shape so identity never rests on color, a gap wherever a week is unknown (never a
// zero), a crosshair tooltip, and a visible table with the same numbers under the chart.
const props = defineProps({
    // [{ week, headcount, strength, in, out, source }]
    points: { type: Array, required: true },
    height: { type: Number, default: 280 },
    // Hide the table (the small multiples show only the line).
    compact: { type: Boolean, default: false },
    // Which series to draw; the small multiples draw headcount only.
    series: { type: Array, default: () => ['headcount', 'strength'] },
    label: { type: String, default: 'Headcount and in roll call by week' },
    // A shared top for small multiples, so their lines compare on one scale.
    max: { type: Number, default: null },
});

const ALL = [
    { key: 'headcount', label: 'Headcount', color: 'var(--chart-1)', shape: 'circle' },
    { key: 'strength', label: 'In roll call', color: 'var(--chart-2)', shape: 'square' },
];
// Newest first, each with its headcount change from the week before (null where either week is unknown).
const rows = computed(() =>
    props.points
        .map((p, i) => {
            const before = props.points[i - 1]?.headcount;
            const known = (v) => v !== null && v !== undefined;

            return { ...p, change: i > 0 && known(p.headcount) && known(before) ? p.headcount - before : null };
        })
        .reverse(),
);
const changeText = (c) => (c === null ? '' : c > 0 ? `+${c}` : String(c));
const changeClass = (c) => (c === null || c === 0 ? 'text-muted-foreground' : c > 0 ? 'text-success' : 'text-destructive');

const SERIES = computed(() => ALL.filter((s) => props.series.includes(s.key)));

const box = useTemplateRef('box');
const { width } = useElementSize(box);
const W = computed(() => Math.max(width.value || 640, 200));
const H = computed(() => props.height);
const PAD = computed(() => ({ left: 34, right: 12, top: 10, bottom: props.compact ? 6 : 22 }));

const known = computed(() => props.points.flatMap((p) => SERIES.value.map((s) => p[s.key])).filter((v) => v !== null && v !== undefined));
const hasData = computed(() => known.value.length > 0);

// A round top at or above the highest value, with at most five gridlines.
const top = computed(() => {
    const max = Math.max(1, props.max ?? 0, ...known.value);
    const step = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500].find((s) => Math.ceil(max / s) <= 4) ?? 1000;

    return Math.ceil(max / step) * step;
});
const ticks = computed(() => {
    const step = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500, 1000].find((s) => top.value / s <= 4 && top.value % s === 0) ?? top.value;

    return Array.from({ length: top.value / step + 1 }, (_, i) => i * step);
});

const span = computed(() => Math.max(props.points.length - 1, 1));
const x = (i) => PAD.value.left + (i * (W.value - PAD.value.left - PAD.value.right)) / span.value;
const y = (v) => PAD.value.top + ((top.value - v) / top.value) * (H.value - PAD.value.top - PAD.value.bottom);

// One path per unbroken run of known weeks.
function path(key) {
    let d = '';
    let pen = false;
    props.points.forEach((p, i) => {
        if (p[key] === null || p[key] === undefined) {
            pen = false;

            return;
        }
        d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(p[key]).toFixed(1)}`;
        pen = true;
    });

    return d;
}

// Week labels thinned to fit, the last always shown.
const labelEvery = computed(() => Math.max(1, Math.ceil(props.points.length / Math.max(2, Math.floor(W.value / 70)))));
const showLabel = (i) => i === props.points.length - 1 || (props.points.length - 1 - i) % labelEvery.value === 0;

const hover = ref(null);
function onMove(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const step = (W.value - PAD.value.left - PAD.value.right) / span.value;
    hover.value = Math.max(0, Math.min(props.points.length - 1, Math.round((event.clientX - rect.left - PAD.value.left) / step)));
}

const fmt = (v) => (v === null || v === undefined ? 'Not known' : String(v));
const SOURCES = { report: 'Sent report', history: 'Placement history', live: 'This week, so far' };
const source = (p) => SOURCES[p.source] ?? 'Not known';
const hoverLabel = (p) => `${weekLabel(p.week)}`;
</script>

<template>
    <div>
        <div v-if="SERIES.length > 1" class="mb-2 flex flex-wrap items-center gap-4 text-xs text-muted-foreground" data-testid="strength-legend">
            <span v-for="s in SERIES" :key="s.key" class="inline-flex items-center gap-1.5">
                <svg width="14" height="8" class="overflow-visible" aria-hidden="true">
                    <line x1="0" x2="14" y1="4" y2="4" :style="{ stroke: s.color }" stroke-width="2" />
                    <circle v-if="s.shape === 'circle'" cx="7" cy="4" r="3" :style="{ fill: s.color }" />
                    <rect v-else x="4" y="1" width="6" height="6" :style="{ fill: s.color }" />
                </svg>
                {{ s.label }}
            </span>
        </div>

        <div ref="box" class="relative" :style="{ height: `${height}px` }">
            <p v-if="!hasData" class="grid h-full place-items-center text-xs text-muted-foreground" data-testid="strength-empty">
                No strength is known for these weeks yet.
            </p>
            <svg
                v-else
                :width="W"
                :height="H"
                class="block overflow-visible"
                role="img"
                :aria-label="compact ? label : `${label}; values in the table that follows`"
                data-testid="strength-svg"
                @pointermove="onMove"
                @pointerleave="hover = null"
            >
                <g v-for="tick in ticks" :key="tick">
                    <line :x1="PAD.left" :x2="W - PAD.right" :y1="y(tick)" :y2="y(tick)" class="stroke-border" :stroke-dasharray="tick === 0 ? '' : '3 3'" />
                    <text :x="PAD.left - 6" :y="y(tick) + 3" text-anchor="end" class="fill-muted-foreground text-[10px]">{{ tick }}</text>
                </g>
                <template v-if="!compact">
                    <text
                        v-for="(p, i) in points"
                        v-show="showLabel(i)"
                        :key="p.week"
                        :x="x(i)"
                        :y="H - 6"
                        :text-anchor="i === points.length - 1 ? 'end' : i === 0 ? 'start' : 'middle'"
                        class="fill-muted-foreground text-[10px]"
                    >
                        {{ weekTick(p.week) }}
                    </text>
                </template>
                <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="PAD.top" :y2="H - PAD.bottom" class="stroke-muted-foreground/50" />
                <template v-for="s in SERIES" :key="s.key">
                    <path :d="path(s.key)" fill="none" :style="{ stroke: s.color }" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" :data-testid="`strength-line-${s.key}`" />
                    <template v-for="(p, i) in points" :key="`${s.key}-${p.week}`">
                        <template v-if="p[s.key] !== null && p[s.key] !== undefined && (!compact || hover === i)">
                            <circle v-if="s.shape === 'circle'" :cx="x(i)" :cy="y(p[s.key])" r="4" :style="{ fill: s.color }" class="stroke-card" stroke-width="2" />
                            <rect v-else :x="x(i) - 4" :y="y(p[s.key]) - 4" width="8" height="8" :style="{ fill: s.color }" class="stroke-card" stroke-width="2" />
                        </template>
                    </template>
                </template>
            </svg>
            <div
                v-if="hover !== null"
                class="pointer-events-none absolute top-0 z-10 rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs shadow-[var(--shadow-panel)]"
                :style="{ left: `${Math.max(0, Math.min(x(hover) + 8, W - 170))}px` }"
                aria-hidden="true"
            >
                <div class="mb-0.5 font-medium">{{ hoverLabel(points[hover]) }}</div>
                <div v-for="s in SERIES" :key="s.key" class="tabular-nums">{{ s.label }} {{ fmt(points[hover][s.key]) }}</div>
                <div class="text-muted-foreground">{{ source(points[hover]) }}</div>
            </div>
        </div>

        <div v-if="!compact" class="mt-4 overflow-x-auto">
            <table class="w-full text-[13px] tabular-nums" data-testid="strength-table">
                <caption class="sr-only">{{ label }}</caption>
                <thead class="text-left text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-1.5 pr-3 font-medium">Week</th>
                        <th scope="col" class="py-1.5 pr-3 text-right font-medium">Headcount</th>
                        <th scope="col" class="py-1.5 pr-3 text-right font-medium">Change</th>
                        <th scope="col" class="py-1.5 pr-3 text-right font-medium">In roll call</th>
                        <th scope="col" class="py-1.5 pr-3 text-right font-medium">Joined</th>
                        <th scope="col" class="py-1.5 pr-3 text-right font-medium">Left</th>
                        <th scope="col" class="py-1.5 pl-4 font-medium">From</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in rows" :key="p.week" class="border-t border-border" :data-week="p.week">
                        <th scope="row" class="py-1.5 pr-3 text-left font-normal">{{ weekLabel(p.week) }}</th>
                        <td class="py-1.5 pr-3 text-right">{{ fmt(p.headcount) }}</td>
                        <td class="py-1.5 pr-3 text-right" :class="changeClass(p.change)" data-testid="strength-change">{{ changeText(p.change) }}</td>
                        <td class="py-1.5 pr-3 text-right">{{ fmt(p.strength) }}</td>
                        <td class="py-1.5 pr-3 text-right">{{ p.in ?? '' }}</td>
                        <td class="py-1.5 pr-3 text-right">{{ p.out ?? '' }}</td>
                        <td class="py-1.5 pl-4 text-muted-foreground">{{ source(p) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
