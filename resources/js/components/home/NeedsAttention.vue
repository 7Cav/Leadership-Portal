<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhCaretRight, PhCheckCircle, PhFileText, PhUserMinus } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import EmptyState from '@/components/EmptyState.vue';

// The "Needs attention" worklist (Story 5.10): reports holding up the chain and at-risk troopers,
// each row a link to the screen that resolves it. The server decides what belongs (the shared
// at-risk definition, the roll-up's holding rule); this only draws it, and says "all clear" when
// the list is empty rather than leaving a blank panel.
const props = defineProps({
    // `{ items, totals }` from HomeController.
    attention: { type: Object, required: true },
});

const holding = computed(() => props.attention.items.filter((item) => item.kind === 'holding'));
const atRisk = computed(() => props.attention.items.filter((item) => item.kind === 'at_risk'));

const groups = computed(() =>
    [
        { key: 'holding', title: 'Holding up the report chain', items: holding.value, total: props.attention.totals.holding, all: '/reports', allLabel: 'See every report' },
        { key: 'at_risk', title: 'At-risk troopers', items: atRisk.value, total: props.attention.totals.atRisk, all: '/roster', allLabel: 'Open the roster' },
    ].filter((group) => group.items.length),
);
</script>

<template>
    <Card role="region" class="min-w-0 gap-0 py-0" aria-labelledby="attention-title" data-testid="needs-attention">
        <div class="flex items-center gap-2 px-4 pt-3.5">
            <h2 id="attention-title" class="text-[15px] font-semibold">Needs attention</h2>
            <span class="hidden truncate text-xs text-muted-foreground sm:inline">Calls to make before the report goes up</span>
        </div>

        <EmptyState v-if="!groups.length" :icon="PhCheckCircle" title="All clear" class="py-12" data-testid="all-clear">
            No report is holding up the chain and no trooper is at risk this week.
        </EmptyState>

        <div v-else class="p-1.5 pb-2">
            <section v-for="group in groups" :key="group.key" :aria-labelledby="`attention-${group.key}`" :data-group="group.key">
                <h3 :id="`attention-${group.key}`" class="flex items-center justify-between px-3 pt-3 pb-1 text-xs font-medium text-muted-foreground">
                    {{ group.title }}
                    <span class="tabular-nums">{{ group.total }}</span>
                </h3>
                <ul>
                    <li v-for="item in group.items" :key="item.id">
                        <Link
                            :href="item.href"
                            class="group grid grid-cols-[1.75rem_minmax(0,1fr)_auto] items-start gap-x-2.5 rounded-md px-3 py-2.5 transition-colors hover:bg-accent/70 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring"
                            :data-item="item.kind"
                        >
                            <span class="grid size-7 place-items-center rounded-md border border-border bg-card text-base" :class="item.kind === 'holding' ? 'text-warning' : 'text-destructive'" aria-hidden="true">
                                <component :is="item.kind === 'holding' ? PhFileText : PhUserMinus" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-[13px] font-medium">{{ item.kind === 'holding' ? item.unit.designation : item.trooper.displayName }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    <template v-if="item.kind === 'holding'">
                                        {{ item.status === 'draft' ? 'Draft report' : 'Report not started' }}, holding up {{ item.holds.designation }}
                                    </template>
                                    <template v-else>
                                        Missed {{ item.missed }} of the last {{ item.windowWeeks }} weeks<template v-if="item.section"> in {{ item.section.designation }}</template>
                                    </template>
                                </span>
                            </span>
                            <span class="flex items-center gap-1.5 pt-0.5">
                                <Badge v-if="item.kind === 'holding'" :variant="item.status === 'draft' ? 'warning' : 'outline'">
                                    {{ item.status === 'draft' ? 'Draft' : 'Not started' }}
                                </Badge>
                                <Badge v-else variant="danger" class="tabular-nums">{{ item.missed }} missed</Badge>
                                <PhCaretRight class="size-3.5 text-muted-foreground" aria-hidden="true" />
                            </span>
                        </Link>
                    </li>
                </ul>
                <p v-if="group.total > group.items.length" class="px-3 pt-1 pb-1 text-xs text-muted-foreground">
                    {{ group.total - group.items.length }} more.
                    <Link :href="group.all" class="underline underline-offset-2 hover:text-foreground">{{ group.allLabel }}</Link>
                </p>
            </section>
        </div>
    </Card>
</template>
