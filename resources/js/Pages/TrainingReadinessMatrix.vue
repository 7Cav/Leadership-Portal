<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowLeft, PhCheck, PhWarning } from '@phosphor-icons/vue';
import { Card } from '@/components/ui/card';
import { compareByBillet } from '@/lib/ranks';

// The training-readiness matrix (Story 6.9): troopers down the side, the course catalog across the top, one mark
// per cell. The server (`Milpac\ReadinessMatrix`) shapes everything and the controller has already gated the unit;
// this page draws it. Coverage under each column is a plain ratio, not a canonical KPI (AD-21).
const props = defineProps({
    unit: { type: Object, required: true },
    courses: { type: Array, required: true },
    troopers: { type: Array, required: true },
    coverage: { type: Array, required: true },
});

const unitLabel = computed(() => (props.unit.callsign ? `${props.unit.callsign} ${props.unit.name}` : props.unit.name));
// The leader, then the assistant leader, then everyone by rank and name, as the mockup lists a unit.
const rows = computed(() => [...props.troopers].sort(compareByBillet));
const coverageByCourse = computed(() => Object.fromEntries(props.coverage.map((entry) => [entry.courseId, entry])));

// The mark for each status, with the words a screen reader gets in place of the glyph.
const MARKS = {
    qualified: { label: 'Qualified', tone: 'border-success/40 bg-success/15 text-success' },
    in_progress: { label: 'In progress', tone: 'border-info/40 bg-info/15 text-info' },
    required: { label: 'Required, not held', tone: 'border-destructive/40 bg-destructive/15 text-destructive' },
};
</script>

<template>
    <Head :title="`Training readiness · ${unitLabel}`" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight" data-testid="matrix-title">Training readiness · {{ unitLabel }}</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">Who holds which course across the unit, so gaps show without opening each trooper.</p>
            </div>
            <Link :href="`/roster/${unit.id}`" class="inline-flex items-center gap-1 text-[13px] text-primary hover:underline" data-testid="back-to-roster">
                <PhArrowLeft class="size-3.5" aria-hidden="true" />Back to the roster
            </Link>
        </div>

        <Card class="gap-0 overflow-hidden py-0" data-testid="matrix">
            <!-- A thin, theme-coloured scrollbar: the browser's default is a bright bar that does not belong on the dark theme. -->
            <div class="overflow-x-auto [scrollbar-color:var(--border)_transparent] [scrollbar-width:thin]">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr class="border-b border-border bg-muted/30">
                            <th scope="col" class="sticky left-0 min-w-56 bg-muted/30 px-4 py-2.5 text-left text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                Trooper
                            </th>
                            <th
                                v-for="course in courses"
                                :key="course.id"
                                scope="col"
                                class="px-3 py-2.5 text-center text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                                :title="course.name"
                                data-testid="course-column"
                            >
                                {{ course.code }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-if="!rows.length" data-testid="matrix-empty">
                            <td :colspan="courses.length + 1" class="px-4 py-8 text-center text-muted-foreground">No active troopers in this unit.</td>
                        </tr>

                        <tr v-for="row in rows" :key="row.id" class="border-b border-border last:border-b-0" data-testid="trooper-row">
                            <th scope="row" class="sticky left-0 bg-card px-4 py-2.5 text-left font-normal">
                                <Link :href="`/troopers/${row.id}`" class="font-medium hover:underline" data-testid="trooper-link">
                                    {{ row.displayName }}
                                </Link>
                                <span class="block text-xs text-muted-foreground">{{ row.billet ?? 'No billet on record' }}</span>
                            </th>
                            <td v-for="course in courses" :key="course.id" class="px-3 py-2.5 text-center" data-testid="cell">
                                <span
                                    v-if="MARKS[row.statuses[course.id]]"
                                    class="inline-flex size-6 items-center justify-center rounded-md border text-xs font-semibold"
                                    :class="MARKS[row.statuses[course.id]].tone"
                                    :data-status="row.statuses[course.id]"
                                >
                                    <PhCheck v-if="row.statuses[course.id] === 'qualified'" class="size-3.5" weight="bold" aria-hidden="true" />
                                    <span v-else-if="row.statuses[course.id] === 'in_progress'" class="size-2 rounded-full bg-current" aria-hidden="true" />
                                    <span v-else aria-hidden="true">!</span>
                                    <span class="sr-only">{{ MARKS[row.statuses[course.id]].label }}</span>
                                </span>
                                <span v-else class="text-muted-foreground" data-status="none"><span aria-hidden="true">–</span><span class="sr-only">Not held</span></span>
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="rows.length">
                        <tr class="border-t border-border bg-muted/30" data-testid="coverage-row">
                            <th scope="row" class="sticky left-0 bg-muted/30 px-4 py-2.5 text-left text-[13px] font-semibold">Coverage</th>
                            <td v-for="course in courses" :key="course.id" class="px-3 py-2.5 text-center font-mono text-xs text-muted-foreground tabular-nums" data-testid="coverage">
                                {{ coverageByCourse[course.id]?.percent ?? 0 }}%
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>

        <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground" data-testid="legend">
            <li class="flex items-center gap-1.5"><span class="inline-flex size-5 items-center justify-center rounded-md border border-success/40 bg-success/15 text-success"><PhCheck class="size-3" weight="bold" aria-hidden="true" /></span>qualified</li>
            <li class="flex items-center gap-1.5"><span class="inline-flex size-5 items-center justify-center rounded-md border border-info/40 bg-info/15 text-info"><span class="size-1.5 rounded-full bg-current" /></span>in progress</li>
            <li class="flex items-center gap-1.5"><span class="inline-flex size-5 items-center justify-center rounded-md border border-destructive/40 bg-destructive/15 text-[11px] font-semibold text-destructive">!</span>required, not held</li>
            <li class="flex items-center gap-1.5"><span class="inline-flex size-5 items-center justify-center">–</span>not held</li>
        </ul>

        <p class="flex items-start gap-2 text-xs text-muted-foreground" data-testid="coverage-note">
            <PhWarning class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            Coverage is the share of the troopers shown who are qualified on each course. It is a plain ratio for spotting bottlenecks, not a reported KPI.
        </p>
    </div>
</template>
