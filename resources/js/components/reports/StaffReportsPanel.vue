<script setup>
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';

// A platoon's or company's HQ staff for the week (policy, 2026-10-29): whether each sent their own staff report and
// attended a section practice. Read only, and live: it is not frozen with this report. The server lists only the
// staff whose report the viewer may read.
defineProps({
    // `[{ trooperId, name, sent, attended, practices, href }]`.
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
                <Badge v-if="!row.sent" variant="warning" class="ml-auto" data-testid="staff-report-state">Not sent</Badge>
                <Badge v-else-if="row.attended" variant="success" class="ml-auto" data-testid="staff-report-state">
                    Attended {{ row.practices }} {{ row.practices === 1 ? 'practice' : 'practices' }}
                </Badge>
                <Badge v-else variant="danger" class="ml-auto" data-testid="staff-report-state">Did not attend</Badge>
            </li>
        </ul>
        <p v-else class="px-4 py-3 text-xs text-muted-foreground" data-testid="staff-reports-empty">No HQ staff to show for this week.</p>
    </Card>
</template>
