<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowLeft, PhInfo, PhUsersThree } from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import ReadinessMark from '@/components/milpac/ReadinessMark.vue';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { compareBillet } from '@/lib/roster';

// The training-readiness matrix (Story 6.9): troopers down the side, the course catalog across the top, one mark
// per cell. The server (`Milpac\ReadinessMatrix`) shapes everything, including each trooper's `role`, and the
// controller has already gated the unit; this page draws it and owns the row order. Coverage under each column is
// a plain ratio, not a canonical KPI (AD-21).
const props = defineProps({
    unit: { type: Object, required: true },
    courses: { type: Array, required: true },
    troopers: { type: Array, required: true },
    coverage: { type: Array, required: true },
});

const unitLabel = computed(() => (props.unit.callsign ? `${props.unit.callsign} ${props.unit.name}` : props.unit.name));
// The leader, then the assistant leader, then everyone by rank and name, with the roster's own comparison: the
// server decides who leads (`role`), so a billet such as "Training Leader" never sorts ahead of the real one.
const rows = computed(() =>
    props.troopers
        .map((trooper) => ({ ...trooper, rank: trooper.rankShort, name: trooper.displayName }))
        .sort(compareBillet),
);
const coverageByCourse = computed(() => Object.fromEntries(props.coverage.map((entry) => [entry.courseId, entry])));

const LEGEND = [
    { status: 'qualified', label: 'qualified' },
    { status: 'in_progress', label: 'in progress' },
    { status: 'required', label: 'required, not held' },
    { status: 'none', label: 'not held' },
];
</script>

<template>
    <Head :title="`Training readiness · ${unitLabel} · 7Cav Leadership Portal`" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 id="matrix-title" class="text-xl font-semibold tracking-tight sm:text-[22px]" data-testid="matrix-title">Training readiness · {{ unitLabel }}</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">Who holds which course across the unit, so gaps show without opening each trooper.</p>
            </div>
            <Link :href="`/roster/${unit.id}`" class="inline-flex items-center gap-1 text-[13px] text-primary hover:underline" data-testid="back-to-roster">
                <PhArrowLeft class="size-3.5" aria-hidden="true" />Back to the roster
            </Link>
        </div>

        <Card class="gap-0 overflow-hidden py-0" data-testid="matrix">
            <EmptyState v-if="!courses.length" :icon="PhUsersThree" title="No courses in the catalog yet" class="py-14" data-testid="matrix-no-courses">
                Courses appear as columns once an Admin adds them to the catalog.
            </EmptyState>

            <TooltipProvider v-else :delay-duration="200">
                <!-- The table's own scroller is capped in height, so a long unit scrolls inside the card and the sticky course header stays in view; focusable so the keyboard can scroll it. -->
                <Table
                    role="region"
                    aria-labelledby="matrix-title"
                    tabindex="0"
                    class="border-collapse text-[13px]"
                    style="max-height: calc(100dvh - 14rem); scrollbar-color: var(--border) transparent; scrollbar-width: thin"
                >
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead
                                scope="col"
                                class="sticky top-0 left-0 z-20 min-w-44 border-r border-border bg-muted px-4 text-[11px] font-medium tracking-wide text-muted-foreground uppercase sm:min-w-56"
                            >
                                Trooper
                            </TableHead>
                            <TableHead
                                v-for="course in courses"
                                :key="course.id"
                                scope="col"
                                class="sticky top-0 z-10 bg-muted px-3 text-center text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                                data-testid="course-column"
                            >
                                <!-- Focusable, so the full name is reachable by keyboard and touch; the name is also in the DOM for screen readers. -->
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span tabindex="0" class="cursor-help rounded-sm underline decoration-dotted underline-offset-4 outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            {{ course.code }}<span class="sr-only"> ({{ course.name }})</span>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>{{ course.name }}</TooltipContent>
                                </Tooltip>
                            </TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <TableRow v-for="row in rows" :key="row.id" data-testid="trooper-row">
                            <TableHead scope="row" class="sticky left-0 z-10 max-w-44 border-r border-border bg-card px-4 py-2.5 text-left font-normal sm:max-w-56">
                                <Link :href="`/troopers/${row.id}`" class="block truncate font-medium hover:underline" data-testid="trooper-link">
                                    {{ row.displayName }}
                                </Link>
                                <span class="block truncate text-xs text-muted-foreground" :title="row.billet ?? undefined">{{ row.billet ?? 'No billet on record' }}</span>
                            </TableHead>
                            <TableCell v-for="course in courses" :key="course.id" class="px-3 py-2.5 text-center" data-testid="cell">
                                <ReadinessMark :status="row.statuses[course.id] ?? 'none'" />
                            </TableCell>
                        </TableRow>
                    </TableBody>

                    <TableFooter v-if="rows.length">
                        <TableRow class="bg-muted/30 hover:bg-muted/30" data-testid="coverage-row">
                            <TableHead scope="row" class="sticky left-0 z-10 border-r border-border bg-muted px-4 text-[13px] font-semibold">Coverage</TableHead>
                            <TableCell v-for="course in courses" :key="course.id" class="px-3 text-center font-mono text-xs text-muted-foreground tabular-nums" data-testid="coverage">
                                {{ coverageByCourse[course.id]?.percent ?? 0 }}%
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>

                <EmptyState v-if="!rows.length" :icon="PhUsersThree" title="No active troopers in this unit" class="border-t border-border py-12" data-testid="matrix-empty">
                    Troopers appear here once the roster places them in a section of this unit.
                    <Link :href="`/roster/${unit.id}`" class="text-primary hover:underline">Back to the roster</Link>
                </EmptyState>
            </TooltipProvider>
        </Card>

        <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground" data-testid="legend">
            <li v-for="item in LEGEND" :key="item.status" class="flex items-center gap-1.5">
                <ReadinessMark :status="item.status" size="sm" decorative />{{ item.label }}
            </li>
        </ul>

        <p class="flex items-start gap-2 text-xs text-muted-foreground" data-testid="coverage-note">
            <PhInfo class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            Coverage is the share of the troopers shown who are qualified on each course. It is a plain ratio for spotting bottlenecks, not a reported KPI.
        </p>
    </div>
</template>
