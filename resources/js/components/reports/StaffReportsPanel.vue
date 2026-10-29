<script setup>
import { Link } from '@inertiajs/vue3';
import StaffReportDiscrepancy from '@/components/reports/StaffReportDiscrepancy.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { attendedText } from '@/lib/staffReport';

// A platoon's, company's or battalion's HQ staff for the week: whether each sent their own staff report and
// attended a section practice. Read only, and live: it is not frozen with this report. The server lists only the
// staff whose report the viewer may read, and says where the game server disagrees (never on the viewer's own).
defineProps({
    // `[{ trooperId, name, sent, attended, practices, practiceType, ftx, officeHour, otherEvents, notes, sheetPushedAt,
    // discrepancy, href }]`; `practices` is a count here.
    rows: { type: Array, required: true },
});
</script>

<template>
    <Card class="gap-0 py-0 text-[13px]" aria-labelledby="staff-reports-title" data-testid="staff-reports-panel">
        <div class="border-b border-border px-4 py-3">
            <h2 id="staff-reports-title" class="text-sm font-semibold">HQ staff reports</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Each HQ staff member attends at least one section practice a week and says so in their own staff report.</p>
        </div>
        <ul v-if="rows.length" class="divide-y divide-border">
            <li v-for="row in rows" :key="row.trooperId" class="flex flex-wrap items-center gap-x-2.5 gap-y-1 px-4 py-2.5" :data-testid="`staff-report-row-${row.trooperId}`">
                <Link :href="row.href" class="font-medium underline-offset-2 hover:underline">{{ row.name }}</Link>
                <span class="ml-auto inline-flex items-center gap-1.5">
                    <StaffReportDiscrepancy :discrepancy="row.discrepancy" />
                    <Badge v-if="!row.sent" variant="warning" data-testid="staff-report-state">Not sent</Badge>
                    <Badge v-else-if="row.attended && (row.practiceType === 'ftx' || row.practiceType === 'office_hour')" variant="success" data-testid="staff-report-state">
                        {{ attendedText({ attended: true, practiceType: row.practiceType, ftx: row.ftx, officeHour: row.officeHour, practices: [] }) }}
                    </Badge>
                    <Badge v-else-if="row.attended" variant="success" data-testid="staff-report-state">
                        Attended {{ row.practices }} {{ row.practices === 1 ? 'practice' : 'practices' }}
                    </Badge>
                    <Badge v-else variant="danger" data-testid="staff-report-state">Did not attend</Badge>
                </span>
                <p v-if="row.officeHour?.note" class="w-full text-xs text-muted-foreground" data-testid="staff-report-office-hour">Office hour: {{ row.officeHour.note }}</p>
                <p v-if="row.otherEvents" class="w-full text-xs text-muted-foreground" data-testid="staff-report-other-events">Other events: {{ row.otherEvents }}</p>
                <p v-if="row.notes" class="w-full text-xs text-muted-foreground" data-testid="staff-report-notes">Notes: {{ row.notes }}</p>
            </li>
        </ul>
        <p v-else class="px-4 py-3 text-xs text-muted-foreground" data-testid="staff-reports-empty">No HQ staff to show for this week.</p>
    </Card>
</template>
