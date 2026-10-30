<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowLeft } from '@phosphor-icons/vue';
import { Card } from '@/components/ui/card';
import StrengthChart from '@/components/roster/StrengthChart.vue';

// A unit's strength trends: headcount and in roll call per Cav week over a chosen range, the change over it, and its
// children's headcounts as small multiples on one scale. Read only; every number comes from StrengthTrends.
const props = defineProps({
    unit: { type: Object, required: true },
    range: { type: String, required: true },
    weeks: { type: Array, required: true },
    change: { type: Object, required: true },
    children: { type: Array, required: true },
});

const RANGES = [
    { value: '12', label: '12 weeks' },
    { value: '26', label: '26 weeks' },
    { value: '52', label: '52 weeks' },
    { value: 'all', label: 'All' },
];

const signed = (v) => (v === null ? 'Not known' : v > 0 ? `+${v}` : String(v));

const childMax = computed(() => Math.max(1, ...props.children.flatMap((c) => c.points.map((p) => p.headcount ?? 0))));
const childChange = (child) => {
    const known = child.points.map((p) => p.headcount).filter((v) => v !== null);

    return known.length < 2 ? null : known[known.length - 1] - known[0];
};
</script>

<template>
    <Head :title="`Strength · ${unit.designation} · 7Cav Leadership Portal`" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]" data-testid="strength-title">
                Strength <span class="text-muted-foreground">{{ unit.designation }}</span>
            </h1>
            <Link :href="`/roster/${unit.id}`" class="inline-flex items-center gap-1 text-[13px] text-primary hover:underline">
                <PhArrowLeft class="size-3.5" aria-hidden="true" />Back to roster
            </Link>
        </div>

        <nav aria-label="Range" class="flex flex-wrap gap-1.5" data-testid="strength-ranges">
            <Link
                v-for="r in RANGES"
                :key="r.value"
                :href="`/roster/${unit.id}/strength?range=${r.value}`"
                preserve-scroll
                class="rounded-md border px-2.5 py-1 text-[13px]"
                :class="r.value === range ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted'"
                :aria-current="r.value === range ? 'page' : undefined"
            >
                {{ r.label }}
            </Link>
        </nav>

        <Card class="p-4">
            <dl class="mb-4 flex flex-wrap gap-x-8 gap-y-2 text-[13px]" data-testid="strength-change">
                <div>
                    <dt class="text-muted-foreground">Headcount change</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ signed(change.headcount) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">In roll call change</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ signed(change.strength) }}</dd>
                </div>
            </dl>
            <StrengthChart :points="weeks" :label="`${unit.designation} headcount and in roll call by week`" />
            <p class="mt-3 text-xs text-muted-foreground">
                Sent weeks show the numbers frozen in the report. Other weeks are rebuilt from where each trooper was placed,
                from late October 2026 on. Weeks with neither are left blank.
            </p>
        </Card>

        <section v-if="children.length" aria-labelledby="strength-children">
            <h2 id="strength-children" class="mb-2 text-[15px] font-semibold">Headcount by unit</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="strength-children">
                <Card v-for="child in children" :key="child.id" class="p-3" :data-unit="child.id">
                    <div class="mb-1 flex items-baseline justify-between gap-2 text-[13px]">
                        <Link :href="`/roster/${child.id}/strength?range=${range}`" class="font-medium hover:underline">{{ child.designation }}</Link>
                        <span class="tabular-nums text-muted-foreground">{{ signed(childChange(child)) }}</span>
                    </div>
                    <StrengthChart
                        :points="child.points"
                        :series="['headcount']"
                        :height="96"
                        :max="childMax"
                        compact
                        :label="`${child.designation} headcount by week`"
                    />
                </Card>
            </div>
        </section>
    </div>
</template>
