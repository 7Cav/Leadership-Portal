<script setup>
import { computed } from 'vue';
import { PhClockCounterClockwise, PhWarning } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { countsOf, displayName, fullName, isLocked, practiceOf, signatureOf } from '@/lib/rollcall';
import { weekLabel } from '@/lib/roster';
import { cn } from '@/lib/utils';

// Step 3 of the guided week: the totals, what still needs a look, and the at-risk warnings.
const props = defineProps({
    troopers: { type: Array, required: true },
    // `[{ trooperId, missed, windowWeeks }]` from the shared KPI service (AD-21).
    atRisk: { type: Array, default: () => [] },
    // `[{ trooper, weeks }]` (`makeupOwedList`): who asked for a make-up and has none recorded yet, weeks oldest first.
    makeupOwed: { type: Array, default: () => [] },
});

const emit = defineEmits(['go']);

const counts = computed(() => countsOf(props.troopers));

const names = (predicate) =>
    props.troopers
        .filter((t) => !isLocked(t) && predicate(t))
        .map(displayName)
        .join(', ');

const blockers = computed(() => {
    const out = [];
    if (counts.value.unmarked) {
        out.push(`${counts.value.unmarked} not marked for section practice: ${names((t) => practiceOf(t) === null)}. They post as "Not marked".`);
    }
    if (counts.value.unsigned) {
        out.push(`${counts.value.unsigned} unsigned: ${names((t) => signatureOf(t) === 'unsigned')}.`);
    }

    return out;
});

const risks = computed(() =>
    props.atRisk
        .map((risk) => ({ ...risk, trooper: props.troopers.find((t) => t.id === risk.trooperId) }))
        .filter((risk) => risk.trooper),
);

const signatureStats = computed(() => [
    { label: 'Will attend', value: counts.value.willAttend },
    { label: 'Will not attend', value: counts.value.cannotAttend },
    { label: 'Make-up requested', value: counts.value.makeupRequested },
    { label: 'Excused', value: counts.value.excusedSignature, tone: 'text-muted-foreground' },
    { label: 'Unsigned', value: counts.value.unsigned, tone: counts.value.unsigned ? 'text-warning' : '' },
]);

const practiceStats = computed(() => [
    { label: 'Attended', value: counts.value.present },
    { label: 'Not attended', value: counts.value.missed, tone: counts.value.missed ? 'text-destructive' : '' },
    { label: 'Excused', value: counts.value.excused, tone: 'text-muted-foreground' },
    { label: 'Make-up complete', value: counts.value.makeup },
    { label: 'LOA', value: counts.value.loa, tone: 'text-muted-foreground' },
]);

const weeksText = (weeks) => `${weeks.length === 1 ? 'the week' : 'the weeks'} ${weeks.map(weekLabel).join(', ')}`;
</script>

<template>
    <div
        v-if="blockers.length"
        class="mt-3 flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/7 px-3.5 py-3 text-[13px]"
        data-testid="review-blockers"
    >
        <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
        <div class="flex-1 space-y-1">
            <p v-for="blocker in blockers" :key="blocker">{{ blocker }}</p>
        </div>
        <Button v-if="counts.unmarked" variant="outline" size="sm" @click="emit('go', 2)">Fix in step 2</Button>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <section aria-labelledby="review-signatures">
            <h3 id="review-signatures" class="mb-2 text-[13px] font-semibold">Signatures</h3>
            <dl class="grid grid-cols-3 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-5">
                <div
                    v-for="(stat, index) in signatureStats"
                    :key="stat.label"
                    :class="cn('flex flex-col bg-card px-3 py-2.5', index === signatureStats.length - 1 && 'col-span-2 sm:col-span-1')"
                >
                    <dt class="text-xs text-muted-foreground">{{ stat.label }}</dt>
                    <dd :class="cn('mt-auto pt-0.5 text-lg font-semibold tabular-nums', stat.tone)">{{ stat.value }}</dd>
                </div>
            </dl>
        </section>
        <section aria-labelledby="review-practice">
            <h3 id="review-practice" class="mb-2 text-[13px] font-semibold">Section practice</h3>
            <dl class="grid grid-cols-3 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-5">
                <div
                    v-for="(stat, index) in practiceStats"
                    :key="stat.label"
                    :class="cn('flex flex-col bg-card px-3 py-2.5', index === practiceStats.length - 1 && 'col-span-2 sm:col-span-1')"
                >
                    <dt class="text-xs text-muted-foreground">{{ stat.label }}</dt>
                    <dd :class="cn('mt-auto pt-0.5 text-lg font-semibold tabular-nums', stat.tone)">{{ stat.value }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="mt-5 grid items-start gap-5 xl:grid-cols-2">
        <section aria-labelledby="review-risk" class="rounded-lg border border-border bg-card" data-testid="at-risk">
            <div class="flex items-baseline justify-between px-4 pt-3.5 pb-1">
                <h3 id="review-risk" class="text-[13px] font-semibold">At-risk warnings</h3>
                <span class="text-xs text-muted-foreground tabular-nums">{{ risks.length }}</span>
            </div>
            <ul v-if="risks.length" class="p-1.5">
                <li v-for="risk in risks" :key="risk.trooperId" class="flex items-start gap-3 rounded-md p-2.5">
                    <PhWarning class="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-medium">{{ fullName(risk.trooper) }} has missed {{ risk.missed }} of the last {{ risk.windowWeeks }} weeks</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">At risk on attendance. Talk to them before the report goes up.</p>
                    </div>
                </li>
            </ul>
            <p v-else class="px-4 pt-1 pb-4 text-xs text-muted-foreground">No one is at risk on attendance this week.</p>
        </section>
        <section aria-labelledby="review-makeups" class="rounded-lg border border-border bg-card" data-testid="makeup-owed">
            <div class="flex items-baseline justify-between px-4 pt-3.5 pb-1">
                <h3 id="review-makeups" class="text-[13px] font-semibold">Owes a make-up</h3>
                <span class="text-xs text-muted-foreground tabular-nums">{{ makeupOwed.length }}</span>
            </div>
            <ul v-if="makeupOwed.length" class="p-1.5">
                <li v-for="row in makeupOwed" :key="row.trooper.id" class="flex items-start gap-3 rounded-md p-2.5">
                    <PhClockCounterClockwise class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-medium">{{ fullName(row.trooper) }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">Make-up requested for {{ weeksText(row.weeks) }}. Mark Make-up complete once it is done.</p>
                    </div>
                </li>
            </ul>
            <p v-else class="px-4 pt-1 pb-4 text-xs text-muted-foreground">No one owes a make-up.</p>
        </section>
        <slot name="changes" />
    </div>

    <slot />
</template>
