<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight, PhUserCheck } from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import StaffHqHealth from '@/components/reports/StaffHqHealth.vue';
import StaffReportDiscrepancy from '@/components/reports/StaffReportDiscrepancy.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { forumDate } from '@/lib/forumDate';
import { stamp } from '@/lib/report';
import { attendedText, sheetText } from '@/lib/staffReport';

// The staff reports overview: every platoon, company and battalion HQ staff member whose staff report the viewer reads, for one
// Cav week, grouped by HQ down the tree. The server says who is listed, each one's status and where the game server
// disagrees; this page only draws it, and filters it to who has not sent or missed the rule.
const props = defineProps({
    // `{ date, start, due, previous, next, ended }`.
    week: { type: Object, required: true },
    // The viewer's own line when they file a report that week, else null. Same shape as a row, without `discrepancy`.
    own: { type: Object, default: null },
    // `[{ id, label, rows: [{ trooperId, name, billet, hq, status, attended, practiceType, ftx, officeHour, practices,
    // otherEvents, notes, sheetPushedAt, metRule, discrepancy, href }] }]`.
    groups: { type: Array, default: () => [] },
    // Whether the reports Google Sheet is set up: only then is a sent report not yet copied there called out.
    sheetConfigured: { type: Boolean, default: false },
    // One listed HQ's unit health for the week ({@see StaffHqHealth.vue}), with `hqs` to switch between them, or null.
    health: { type: Object, default: null },
});

const STATUS = {
    sent: { label: 'Sent', variant: 'success' },
    notSent: { label: 'Not sent', variant: 'warning' },
    notDue: { label: 'Not due yet', variant: 'outline' },
};

const FILTERS = [
    { value: 'all', label: 'All', test: () => true },
    { value: 'notSent', label: 'Not sent', test: (row) => row.status === 'notSent' },
    { value: 'missed', label: 'Missed', test: (row) => row.metRule === false },
];
const filter = ref('all');
const counts = computed(() => Object.fromEntries(FILTERS.map((option) => [option.value, props.groups.reduce((sum, group) => sum + group.rows.filter(option.test).length, 0)])));
const shown = computed(() => {
    const test = FILTERS.find((option) => option.value === filter.value).test;

    return props.groups.map((group) => ({ ...group, rows: group.rows.filter(test) })).filter((group) => group.rows.length);
});

const weekUrl = (date) => `/staff-reports?week=${date}`;
const hqUrl = (id) => `/staff-reports?week=${props.week.date}&hq=${id}`;

// The whole row opens the report. Its name is the one real link (keyboard and screen reader reach it there); a click
// anywhere else on the row follows it, and a click on a link or button inside the row is left to that control, so
// nothing opens twice.
function openRow(event, row) {
    if (event.target.closest('a, button, [role="button"]')) return;
    if (window.getSelection?.()?.toString()) return;
    router.visit(row.href);
}
</script>

<template>
    <Head :title="`Staff reports, week of ${forumDate(week.start)} · 7Cav Leadership Portal`" />

    <div class="flex max-w-5xl flex-col gap-4" data-testid="staff-reports-page">
        <section aria-labelledby="page-title" class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">Staff reports</p>
                <h1 id="page-title" class="text-xl font-semibold tracking-tight sm:text-[22px]">Week of {{ forumDate(week.start) }}</h1>
            </div>
            <nav class="flex items-center gap-1" aria-label="Choose week">
                <Button variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekUrl(week.previous)" :aria-label="`Previous week, ${forumDate(week.previous)}`"><PhCaretLeft /></Link>
                </Button>
                <Button v-if="week.next" variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekUrl(week.next)" :aria-label="`Next week, ${forumDate(week.next)}`"><PhCaretRight /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next week, not open yet"><PhCaretRight /></Button>
            </nav>
            <p class="w-full text-xs text-muted-foreground sm:ml-auto sm:w-auto sm:text-right" data-testid="due-line">
                <template v-if="week.ended">Due {{ stamp(week.due) }}</template>
                <template v-else>Sent once the week ends</template>
            </p>
        </section>

        <Card v-if="own" class="flex flex-row flex-wrap items-center gap-3 px-4 py-3 text-[13px]" data-testid="own-staff-report">
            <div class="min-w-0">
                <p class="font-medium">Your staff report, {{ own.hq }} HQ</p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="own.status === 'sent'">Attended: {{ attendedText(own) }}</template>
                    <template v-else-if="own.status === 'notSent'">Not sent yet</template>
                    <template v-else>You can send it once the week ends</template>
                </p>
            </div>
            <Button v-if="own.status === 'notSent'" size="sm" class="ml-auto" as-child>
                <Link :href="own.href" data-testid="own-staff-report-send">Send your staff report</Link>
            </Button>
            <Button v-else variant="outline" size="sm" class="ml-auto" as-child>
                <Link :href="own.href" data-testid="own-staff-report-open">Open your staff report</Link>
            </Button>
        </Card>

        <StaffHqHealth v-if="health" :health="health" :hq-href="hqUrl" />

        <div v-if="groups.length" class="flex gap-1 self-start rounded-lg bg-muted p-1" role="radiogroup" aria-label="Show" data-testid="staff-reports-filter">
            <button
                v-for="option in FILTERS"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="filter === option.value"
                class="flex items-center gap-1.5 rounded-md px-3 py-1 text-[13px] font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="filter === option.value ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :data-testid="`filter-${option.value}`"
                @click="filter = option.value"
            >
                {{ option.label }}<span class="text-xs text-muted-foreground tabular-nums">{{ counts[option.value] }}</span>
            </button>
        </div>

        <Card v-if="!groups.length" class="py-10" data-testid="staff-reports-none">
            <EmptyState :icon="PhUserCheck" title="No HQ staff this week" />
        </Card>
        <Card v-else-if="!shown.length" class="py-10" data-testid="staff-reports-filtered-none">
            <EmptyState :icon="PhUserCheck" :title="filter === 'notSent' ? 'Everyone has sent theirs' : 'Nobody missed the rule'" />
        </Card>

        <Card v-for="group in shown" :key="group.id" class="gap-0 py-0 text-[13px]" :data-testid="`staff-reports-hq-${group.id}`">
            <h2 class="border-b px-4 py-2.5 text-sm font-semibold">{{ group.label }} HQ</h2>
            <table class="w-full">
                <thead class="hidden text-left text-xs text-muted-foreground sm:table-header-group">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Reported</th>
                        <th class="px-4 py-2 font-medium">1 SP a week</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in group.rows"
                        :key="row.trooperId"
                        class="grid cursor-pointer grid-cols-[1fr_auto] gap-x-3 gap-y-1 border-t px-4 py-2.5 transition-colors focus-within:bg-accent/50 hover:bg-accent/50 sm:table-row sm:p-0"
                        :data-testid="`staff-reports-row-${row.trooperId}`"
                        @click="openRow($event, row)"
                    >
                        <td class="min-w-0 sm:px-4 sm:py-2">
                            <Link
                                :href="row.href"
                                class="rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
                                data-testid="row-link"
                            >{{ row.name }}</Link>
                            <p class="truncate text-xs text-muted-foreground">{{ row.billet }}</p>
                        </td>
                        <td class="justify-self-end sm:px-4 sm:py-2">
                            <Badge :variant="STATUS[row.status].variant" data-testid="row-status">{{ STATUS[row.status].label }}</Badge>
                        </td>
                        <td class="col-span-2 min-w-0 sm:px-4 sm:py-2">
                            <span class="inline-flex items-center gap-1.5">
                                <span data-testid="row-attended">{{ attendedText(row) }}</span>
                                <StaffReportDiscrepancy :discrepancy="row.discrepancy" />
                            </span>
                            <p v-if="row.officeHour?.note" class="text-xs text-muted-foreground" data-testid="row-office-hour">Office hour: {{ row.officeHour.note }}</p>
                            <p v-if="row.otherEvents" class="text-xs text-muted-foreground" data-testid="row-other-events">Other events: {{ row.otherEvents }}</p>
                            <p v-if="row.notes" class="text-xs text-muted-foreground" data-testid="row-notes">Notes: {{ row.notes }}</p>
                            <p
                                v-if="row.status === 'sent' && sheetText(row.sheetPushedAt, sheetConfigured)"
                                class="text-xs text-muted-foreground"
                                data-testid="row-sheet"
                            >
                                {{ sheetText(row.sheetPushedAt, sheetConfigured) }}
                            </p>
                        </td>
                        <td class="col-span-2 sm:px-4 sm:py-2">
                            <Badge v-if="row.metRule === true" variant="success" class="font-normal" data-testid="row-rule">1 SP met</Badge>
                            <Badge v-else-if="row.metRule === false" variant="danger" class="font-normal" data-testid="row-rule">Missed</Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </div>
</template>
