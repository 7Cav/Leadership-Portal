<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhCheckCircle, PhClock, PhMinus, PhUsersThree, PhWarning } from '@phosphor-icons/vue';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { STEPS, stepCells } from '@/lib/home';
import EmptyState from '@/components/EmptyState.vue';

// "Section progress" (shell-f.html): one row per section in scope, the week's five steps across.
// Each step links to the screen that does it: the rollcall for the first four, the report for the
// last. Values are the server's counts (rollcall, attendance and report facts), never estimated.
const props = defineProps({
    steps: { type: Array, required: true },
});

const ICONS = { ok: PhCheckCircle, warn: PhWarning, wait: PhClock, none: PhMinus };
const TONE = { ok: 'text-success', warn: 'text-warning', wait: 'text-muted-foreground', none: 'text-muted-foreground' };
// Which screen each column leads to: columns 0-3 are the rollcall, 4 the report.
const LINK = ['rollcall', 'rollcall', 'rollcall', 'rollcall', 'report'];
const KEYS = ['posted', 'signed', 'held', 'marked', 'report'];

const rows = computed(() => props.steps.map((step) => ({ step, cells: stepCells(step) })));
const barTone = (percent) => (percent >= 90 ? 'bg-success' : percent >= 60 ? 'bg-warning' : 'bg-destructive');
</script>

<template>
    <Card role="region" class="min-w-0 gap-0 py-0" aria-labelledby="progress-title" data-testid="step-matrix">
        <div class="px-4 pt-3.5">
            <h2 id="progress-title" class="text-[15px] font-semibold">Section progress</h2>
        </div>
        <div class="mt-2 max-h-[28rem] overflow-auto border-t border-border">
            <Table class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-auto min-w-[8.5rem] py-2 text-xs font-medium text-muted-foreground">Unit</TableHead>
                        <TableHead v-for="step in STEPS" :key="step" class="h-auto py-2 text-xs font-medium text-muted-foreground">{{ step }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody v-if="rows.length">
                    <TableRow v-for="{ step, cells } in rows" :key="step.id" :data-section="step.id">
                        <TableCell class="py-2">
                            <span class="block font-medium">{{ step.designation }}</span>
                            <span v-if="step.platoon" class="block text-xs text-muted-foreground">{{ step.platoon }}</span>
                        </TableCell>
                        <TableCell v-for="(key, index) in KEYS" :key="key" class="py-2 whitespace-nowrap">
                            <Link
                                :href="step.links[LINK[index]]"
                                class="inline-flex items-center gap-1.5 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :data-step="key"
                                :data-state="cells[key].state"
                                :title="`${STEPS[index]}: ${cells[key].text}. Open the ${LINK[index]}.`"
                            >
                                <template v-if="key === 'signed' && cells.signed.percent !== null">
                                    <span class="w-9 text-right tabular-nums">{{ cells.signed.percent }}%</span>
                                    <span class="h-1 w-11 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                        <span class="block h-full rounded-full" :class="barTone(cells.signed.percent)" :style="{ width: `${cells.signed.percent}%` }" />
                                    </span>
                                    <span class="sr-only">{{ cells.signed.text }}</span>
                                </template>
                                <template v-else>
                                    <component :is="ICONS[cells[key].state]" class="size-4 shrink-0" :class="TONE[cells[key].state]" aria-hidden="true" />
                                    <span :class="cells[key].state === 'warn' ? TONE.warn : cells[key].state === 'ok' ? '' : 'text-muted-foreground'">{{ cells[key].text }}</span>
                                </template>
                            </Link>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
        <p v-if="rows.length" class="flex flex-wrap gap-x-4 gap-y-1 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1.5"><PhCheckCircle class="size-4 text-success" aria-hidden="true" />Done</span>
            <span class="inline-flex items-center gap-1.5"><PhWarning class="size-4 text-warning" aria-hidden="true" />Draft</span>
            <span class="inline-flex items-center gap-1.5"><PhClock class="size-4" aria-hidden="true" />Not yet</span>
        </p>
        <!-- Outside the scrolling table, so it stays centred on a phone. -->
        <EmptyState v-else :icon="PhUsersThree" title="No sections reporting yet" class="py-10">
            Each section's steps for the week show here once troopers are mapped to sections and rollcalls go out.
        </EmptyState>
    </Card>
</template>
