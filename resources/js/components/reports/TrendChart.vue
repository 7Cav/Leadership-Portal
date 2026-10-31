<script setup>
import { computed, ref, useTemplateRef } from 'vue';
import { useElementSize } from '@vueuse/core';
import { weekLabel } from '@/lib/forumDate';
import { percent } from '@/lib/report';

// RC % and SP % over the last eight weeks (P3 mini chart). Earlier weeks are their frozen sent
// snapshots; a week with no sent report is a gap in the line, never an estimate. The last point
// is this report. Two series, one 0–100 axis, legend always shown, markers differ in shape so
// identity never rests on colour, and a screen-reader table carries every value.
const props = defineProps({
    points: { type: Array, required: true },
    // Whether the last point is still live (a draft) or frozen.
    live: { type: Boolean, default: false },
});

const SERIES = [
    { key: 'rc', label: 'Roll call %', color: 'var(--chart-1)', shape: 'circle' },
    { key: 'sp', label: 'SP %', color: 'var(--chart-2)', shape: 'square' },
];

const box = useTemplateRef('box');
const { width } = useElementSize(box);
const W = computed(() => Math.max(width.value || 480, 240));
const H = 132;
const PAD = { left: 30, right: 12, top: 8, bottom: 20 };

const values = computed(() =>
    props.points.map((p) => ({ week: p.week, current: p.current, rc: percent(p.rc), sp: percent(p.sp) })),
);

// The axis floor drops in steps of ten to fit the lowest value, so a 70–100 history is not
// flattened against a 0 baseline, but never hides a low week.
const low = computed(() => {
    const all = values.value.flatMap((v) => [v.rc, v.sp]).filter((v) => v !== null);
    if (!all.length) return 0;

    return Math.max(0, Math.min(60, Math.floor((Math.min(...all) - 5) / 20) * 20));
});
// Round steps that give at most five gridlines.
const ticks = computed(() => {
    const span = 100 - low.value;
    const step = [5, 10, 20, 25].find((s) => span % s === 0 && span / s <= 4) ?? span;

    return Array.from({ length: span / step + 1 }, (_, i) => low.value + i * step);
});

const x = (i) => PAD.left + (i * (W.value - PAD.left - PAD.right)) / Math.max(values.value.length - 1, 1);
const y = (v) => PAD.top + ((100 - v) / (100 - low.value || 1)) * (H - PAD.top - PAD.bottom);

// One path per unbroken run of weeks.
function path(key) {
    let d = '';
    let pen = false;
    values.value.forEach((v, i) => {
        if (v[key] === null) {
            pen = false;

            return;
        }
        d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v[key]).toFixed(1)}`;
        pen = true;
    });

    return d;
}

const hasData = computed(() => values.value.some((v) => v.rc !== null || v.sp !== null));

const hover = ref(null);
function onMove(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const step = (W.value - PAD.left - PAD.right) / Math.max(values.value.length - 1, 1);
    hover.value = Math.max(0, Math.min(values.value.length - 1, Math.round((event.clientX - rect.left - PAD.left) / step)));
}

const fmt = (v) => (v === null ? 'no sent report' : `${v}%`);
const hoverLabel = (v) => `Week Ending ${weekLabel(v.week)}${v.current ? (props.live ? ', this draft' : ', this report') : ''}`;
</script>

<template>
    <div>
        <div class="mb-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
            <h3 class="text-[13px] font-semibold">Last 8 weeks</h3>
            <div class="ml-auto flex items-center gap-4 text-xs text-muted-foreground" aria-hidden="true">
                <span v-for="s in SERIES" :key="s.key" class="inline-flex items-center gap-1.5">
                    <svg width="14" height="8" class="overflow-visible">
                        <line x1="0" x2="14" y1="4" y2="4" :style="{ stroke: s.color }" stroke-width="2" />
                        <circle v-if="s.shape === 'circle'" cx="7" cy="4" r="3" :style="{ fill: s.color }" />
                        <rect v-else x="4" y="1" width="6" height="6" :style="{ fill: s.color }" />
                    </svg>
                    {{ s.label }}
                </span>
            </div>
        </div>

        <div ref="box" class="relative h-[132px]">
            <p v-if="!hasData" class="grid h-full place-items-center text-xs text-muted-foreground">
                The trend fills in as weekly reports are sent.
            </p>
            <svg
                v-else
                :width="W"
                :height="H"
                class="block overflow-visible"
                role="img"
                aria-label="Roll call and SP percentage over the last 8 weeks; values in the table that follows"
                @pointermove="onMove"
                @pointerleave="hover = null"
            >
                <g v-for="tick in ticks" :key="tick">
                    <line
                        :x1="PAD.left"
                        :x2="W - PAD.right"
                        :y1="y(tick)"
                        :y2="y(tick)"
                        class="stroke-border"
                        :stroke-dasharray="tick === low ? '' : '3 3'"
                    />
                    <text :x="PAD.left - 6" :y="y(tick) + 3" text-anchor="end" class="fill-muted-foreground text-[10px]">{{ tick }}</text>
                </g>
                <text
                    v-for="(v, i) in values"
                    v-show="W > 420 || i % 2 === 1 || i === values.length - 1"
                    :key="v.week"
                    :x="x(i)"
                    :y="H - 5"
                    :text-anchor="i === values.length - 1 ? 'end' : i === 0 ? 'start' : 'middle'"
                    class="fill-muted-foreground text-[10px]"
                    :class="{ 'font-semibold': v.current }"
                >
                    {{ weekLabel(v.week).slice(0, 5) }}
                </text>
                <line
                    v-if="hover !== null"
                    :x1="x(hover)"
                    :x2="x(hover)"
                    :y1="PAD.top"
                    :y2="H - PAD.bottom"
                    class="stroke-muted-foreground/50"
                />
                <template v-for="s in SERIES" :key="s.key">
                    <path :d="path(s.key)" fill="none" :style="{ stroke: s.color }" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    <template v-for="(v, i) in values" :key="`${s.key}-${v.week}`">
                        <template v-if="v[s.key] !== null">
                            <circle
                                v-if="s.shape === 'circle'"
                                :cx="x(i)"
                                :cy="y(v[s.key])"
                                :r="v.current ? 4 : 3"
                                :style="{ fill: s.color }"
                                class="stroke-card"
                                stroke-width="2"
                            />
                            <rect
                                v-else
                                :x="x(i) - (v.current ? 4 : 3)"
                                :y="y(v[s.key]) - (v.current ? 4 : 3)"
                                :width="v.current ? 8 : 6"
                                :height="v.current ? 8 : 6"
                                :style="{ fill: s.color }"
                                class="stroke-card"
                                stroke-width="2"
                            />
                        </template>
                    </template>
                </template>
            </svg>
            <div
                v-if="hover !== null"
                class="pointer-events-none absolute top-0 z-10 rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs shadow-[var(--shadow-panel)]"
                :style="{ left: `${Math.min(x(hover) + 8, W - 150)}px` }"
                aria-hidden="true"
            >
                <div class="mb-0.5 font-medium">{{ hoverLabel(values[hover]) }}</div>
                <div class="tabular-nums">Roll call {{ fmt(values[hover].rc) }}</div>
                <div class="tabular-nums">SP {{ fmt(values[hover].sp) }}</div>
            </div>
        </div>

        <div class="sr-only">
        <table>
            <caption>Roll call and SP percentage by week</caption>
            <thead>
                <tr><th scope="col">Week ending</th><th scope="col">Roll call %</th><th scope="col">SP %</th></tr>
            </thead>
            <tbody>
                <tr v-for="v in values" :key="v.week">
                    <th scope="row">{{ hoverLabel(v) }}</th>
                    <td>{{ fmt(v.rc) }}</td>
                    <td>{{ fmt(v.sp) }}</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</template>
