<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhCalendarX, PhCaretRight, PhCheckCircle, PhClockCounterClockwise, PhEnvelopeSimple, PhFileText, PhUserMinus } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import EmptyState from '@/components/EmptyState.vue';
import { makeupPmUrl } from '@/lib/makeupPm';
import { weekLabel } from '@/lib/roster';

// The "Needs attention" worklist (Story 5.10): reports holding up the chain, at-risk troopers and leave posts the
// LOA sync could not read, each row a link to the screen that resolves it. The server decides what belongs (the shared
// at-risk definition, the roll-up's holding rule); this only draws it, and says "all clear" when
// the list is empty rather than leaving a blank panel.
const props = defineProps({
    // `{ items, totals }` from HomeController.
    attention: { type: Object, required: true },
});

// An at-risk row also offers a makeup PM: a link that opens the forum's own "start conversation" form,
// pre-filled, for the leader to send there. None for a trooper with no forum username.
const page = usePage();
const makeupHref = (item) =>
    item.kind === 'at_risk' && item.missedWeeks?.length ? makeupPmUrl(page.props.forum?.baseUrl, item.trooper.username, item.missedWeeks) : null;

const holding = computed(() => props.attention.items.filter((item) => item.kind === 'holding'));
const atRisk = computed(() => props.attention.items.filter((item) => item.kind === 'at_risk'));
const loaReview = computed(() => props.attention.items.filter((item) => item.kind === 'loa_review'));
// Asked for a make-up on the roll call and none recorded yet (2026-10-30).
const makeupOwed = computed(() => props.attention.items.filter((item) => item.kind === 'makeup_owed'));

const ICONS = { holding: PhFileText, at_risk: PhUserMinus, loa_review: PhCalendarX, makeup_owed: PhClockCounterClockwise };
const TONES = { holding: 'text-warning', at_risk: 'text-destructive', loa_review: 'text-warning', makeup_owed: 'text-info' };

const weeksText = (weeks) => `${weeks.length === 1 ? 'the week' : 'the weeks'} ${weeks.map(weekLabel).join(', ')}`;

function rowTitle(item) {
    if (item.kind === 'holding') return `Open the ${item.unit.designation} report for this week`;
    if ((item.kind === 'loa_review' || item.kind === 'makeup_owed') && item.section) return `Open ${item.trooper.displayName} on the ${item.section.designation} roll call`;

    return `Open ${item.trooper.displayName} on the roster`;
}

const groups = computed(() =>
    [
        { key: 'holding', title: 'Holding up the report chain', items: holding.value, total: props.attention.totals.holding, all: '/reports', allLabel: 'See every report' },
        { key: 'at_risk', title: 'At-risk troopers', items: atRisk.value, total: props.attention.totals.atRisk, all: '/roster', allLabel: 'Open the roster' },
        { key: 'loa_review', title: 'LOA posts that need review', items: loaReview.value, total: props.attention.totals.loaReview ?? 0, all: '/roster', allLabel: 'Open the roster' },
        { key: 'makeup_owed', title: 'Owe a make-up', items: makeupOwed.value, total: props.attention.totals.makeupOwed ?? 0, all: '/rollcall', allLabel: 'Open the roll call' },
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
            No report is holding up the chain, no trooper is at-risk or owes a make-up, and no LOA post needs review this week.
        </EmptyState>

        <div v-else class="p-1.5 pb-2">
            <section v-for="group in groups" :key="group.key" :aria-labelledby="`attention-${group.key}`" :data-group="group.key">
                <h3 :id="`attention-${group.key}`" class="flex items-center justify-between px-3 pt-3 pb-1 text-xs font-medium text-muted-foreground">
                    {{ group.title }}
                    <span class="tabular-nums">{{ group.total }}</span>
                </h3>
                <ul>
                    <li v-for="item in group.items" :key="item.id" class="flex items-start gap-1">
                        <Link
                            :href="item.href"
                            class="group grid min-w-0 flex-1 grid-cols-[1.75rem_minmax(0,1fr)_auto] items-start gap-x-2.5 rounded-md px-3 py-2.5 transition-colors hover:bg-accent/70 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring"
                            :data-item="item.kind"
                            :title="rowTitle(item)"
                        >
                            <span class="grid size-7 place-items-center rounded-md border border-border bg-card text-base" :class="TONES[item.kind]" aria-hidden="true">
                                <component :is="ICONS[item.kind]" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-[13px] font-medium">{{ item.kind === 'holding' ? item.unit.designation : item.trooper.displayName }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    <template v-if="item.kind === 'holding'">
                                        {{ item.status === 'draft' ? 'Draft report' : 'Report not started' }}, holding up {{ item.holds.designation }}
                                    </template>
                                    <template v-else-if="item.kind === 'makeup_owed'">
                                        Make-up requested for {{ weeksText(item.weeks) }}<template v-if="item.section"> in {{ item.section.designation }}</template>
                                    </template>
                                    <template v-else-if="item.kind === 'loa_review'">
                                        {{ item.typeLabel ?? 'LOA' }} post the sync could not read<template v-if="item.section"> in {{ item.section.designation }}</template>
                                        <span v-if="item.reviewReason" class="block truncate" data-testid="loa-review-reason">{{ item.reviewReason }}</span>
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
                                <Badge v-else-if="item.kind === 'loa_review'" variant="warning">Needs review</Badge>
                                <Badge v-else-if="item.kind === 'makeup_owed'" variant="info">Owes a make-up</Badge>
                                <Badge v-else variant="danger" class="tabular-nums">{{ item.missed }} missed</Badge>
                                <PhCaretRight class="size-3.5 text-muted-foreground" aria-hidden="true" />
                            </span>
                        </Link>
                        <!-- A sibling of the row's link, never inside it: one link may not hold another. -->
                        <Button v-if="makeupHref(item)" as-child variant="ghost" size="icon-sm" class="mt-2 mr-1 flex-none text-muted-foreground">
                            <a
                                :href="makeupHref(item)"
                                target="_blank"
                                rel="noopener noreferrer"
                                title="Send makeup PM on the forum"
                                data-testid="makeup-pm"
                            >
                                <PhEnvelopeSimple aria-hidden="true" />
                                <span class="sr-only">Send makeup PM to {{ item.trooper.displayName }} (opens the forum in a new tab)</span>
                            </a>
                        </Button>
                        <Button v-if="item.kind === 'loa_review' && item.sourceThread" as-child variant="ghost" size="icon-sm" class="mt-2 mr-1 flex-none text-muted-foreground">
                            <a :href="item.sourceThread" target="_blank" rel="noopener noreferrer" title="View the LOA post on the forum" data-testid="loa-thread">
                                <PhArrowSquareOut aria-hidden="true" />
                                <span class="sr-only">View the LOA post of {{ item.trooper.displayName }} (opens the forum in a new tab)</span>
                            </a>
                        </Button>
                    </li>
                </ul>
                <p v-if="group.total > group.items.length" class="px-3 pt-1 pb-1 text-xs text-muted-foreground">
                    {{ group.total - group.items.length }} more
                    <Link :href="group.all" class="underline underline-offset-2 hover:text-foreground">{{ group.allLabel }}</Link>
                </p>
            </section>
        </div>
    </Card>
</template>
