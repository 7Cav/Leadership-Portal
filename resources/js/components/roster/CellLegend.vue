<script setup>
import WeekCell from './WeekCell.vue';

// What each half of a week cell means (roster-s3.html `#legend`). Shared by both roster views so
// a cell reads the same in either.
defineProps({
    aggregate: { type: Boolean, default: false },
});

const RC = [
    ['ok', 'Will attend'],
    ['cn', 'Will not attend'],
    ['mr', 'Make-up requested'],
    ['bad', 'Unsigned'],
    ['open', 'Unsigned (roll call open)'],
    ['nil', 'No roll call posted'],
];
const SP = [
    ['ok', 'Attended'],
    ['mk', 'Make-up complete'],
    ['bad', 'Not attended'],
    ['open', 'Not marked yet'],
    ['nil', 'No SP held'],
];
</script>

<template>
    <div id="roster-legend" class="grid items-start gap-2 px-3 py-2.5 text-xs text-muted-foreground sm:px-4 lg:grid-cols-[auto_1fr] lg:gap-x-6">
        <div class="flex items-center gap-2.5">
            <span aria-hidden="true"><WeekCell rc="ok" sp="mk" /></span>
            <span class="leading-tight">Each cell is one Cav week, Sat to Fri.<br />Top half roll call, bottom half SP.</span>
        </div>
        <div class="space-y-1.5">
            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                <span class="w-full font-medium text-foreground sm:w-20">Roll call</span>
                <span v-for="[state, label] in RC" :key="state" class="inline-flex items-center gap-1.5">
                    <span class="roster-h inline-block w-[1.125rem]" :class="`roster-h--${state}`" aria-hidden="true" />{{ label }}
                </span>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                <span class="w-full font-medium text-foreground sm:w-20">SP</span>
                <span v-for="[state, label] in SP" :key="state" class="inline-flex items-center gap-1.5">
                    <span class="roster-h inline-block w-[1.125rem]" :class="`roster-h--${state}`" aria-hidden="true" />{{ label }}
                </span>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                <span class="w-full font-medium text-foreground sm:w-20">Both</span>
                <span class="inline-flex items-center gap-1.5"><span class="roster-h roster-h--loa inline-block w-[1.125rem]" aria-hidden="true" />LOA</span>
                <span class="inline-flex items-center gap-1.5"><span class="roster-h roster-h--ex inline-block w-[1.125rem]" aria-hidden="true" />Excused</span>
                <span class="inline-flex items-center gap-1.5"><span class="roster-h roster-h--out inline-block w-[1.125rem]" aria-hidden="true" />Not in the unit yet</span>
                <span class="inline-flex items-center gap-1.5"><span class="roster-cc roster-cc--auto w-[1.125rem]" aria-hidden="true"><span class="roster-h roster-h--ok" /></span>Auto capture, game server</span>
                <span v-if="aggregate" class="inline-flex items-center gap-1.5">
                    <span class="roster-h roster-h--g4 inline-block w-3" aria-hidden="true" /><span class="roster-h roster-h--g3 inline-block w-3" aria-hidden="true" />
                    <span class="roster-h roster-h--g2 inline-block w-3" aria-hidden="true" /><span class="roster-h roster-h--g1 inline-block w-3" aria-hidden="true" />
                    Section rows: 85%+, 70%+, 55%+, below
                </span>
            </div>
        </div>
    </div>
</template>
