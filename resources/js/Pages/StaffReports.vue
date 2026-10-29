<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight, PhUserCheck } from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { forumDate } from '@/lib/forumDate';
import { stamp } from '@/lib/report';

// The staff reports overview: every platoon and company HQ staff member whose staff report the viewer reads, for one
// Cav week, grouped by HQ down the tree. The server says who is listed and each one's status; this page only draws it.
defineProps({
    // `{ date, start, due, previous, next, ended }`.
    week: { type: Object, required: true },
    // The viewer's own line when they file a report that week, else null. Same shape as a row.
    own: { type: Object, default: null },
    // `[{ id, label, rows: [{ trooperId, name, billet, hq, status, attended, practices, metRule, href }] }]`.
    groups: { type: Array, default: () => [] },
});

const STATUS = {
    sent: { label: 'Sent', variant: 'success' },
    notSent: { label: 'Not sent yet', variant: 'warning' },
    notDue: { label: 'Not due yet', variant: 'outline' },
};

const weekUrl = (date) => `/staff-reports?week=${date}`;

function attendedText(row) {
    if (row.attended === null) return '';
    if (!row.attended) return 'No';

    return `Yes: ${row.practices.join(', ')}`;
}

function ruleText(row) {
    if (row.metRule === null) return '';

    return row.metRule ? 'Met' : 'Not met';
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
                <template v-if="week.ended">Due {{ stamp(week.due) }}Z</template>
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

        <Card v-if="!groups.length" class="py-10" data-testid="staff-reports-none">
            <EmptyState :icon="PhUserCheck" title="No HQ staff this week" />
        </Card>

        <Card v-for="group in groups" :key="group.id" class="gap-0 overflow-x-auto py-0 text-[13px]" :data-testid="`staff-reports-hq-${group.id}`">
            <h2 class="border-b px-4 py-2.5 text-sm font-semibold">{{ group.label }} HQ</h2>
            <table class="w-full">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Billet</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Attended</th>
                        <th class="px-4 py-2 font-medium">1 practice a week</th>
                        <th class="px-4 py-2"><span class="sr-only">Report</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in group.rows" :key="row.trooperId" class="border-t" :data-testid="`staff-reports-row-${row.trooperId}`">
                        <td class="px-4 py-2 font-medium">{{ row.name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ row.billet }}</td>
                        <td class="px-4 py-2">
                            <Badge :variant="STATUS[row.status].variant">{{ STATUS[row.status].label }}</Badge>
                        </td>
                        <td class="px-4 py-2">{{ attendedText(row) }}</td>
                        <td class="px-4 py-2">{{ ruleText(row) }}</td>
                        <td class="px-4 py-2 text-right">
                            <Link :href="row.href" class="underline-offset-4 hover:underline">Open report</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </div>
</template>
