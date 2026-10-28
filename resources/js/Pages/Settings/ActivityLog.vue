<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight, PhFootprints, PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { forumDateTime } from '@/lib/forumDate';

// The pages each user opened (#382), newest first, for retracing a bug report. `visits` is a Laravel paginator as-is.
// Each visit is `{ id, user, url, actingAs, viewingAs, visited_at }`; `actingAs` / `viewingAs` are what the shell named
// for an act as or a view as in effect on that visit, or null.
const props = defineProps({
    visits: { type: Object, required: true },
    filters: { type: Object, required: true },
    userOptions: { type: Array, required: true },
    retentionDays: { type: Number, required: true },
});

const filters = reactive({
    user_id: props.filters.user_id ?? null,
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

function applyFilters() {
    router.get('/settings/activity-log', filters, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    filters.user_id = null;
    filters.date_from = '';
    filters.date_to = '';
    applyFilters();
}

function goTo(url) {
    if (url === null) {
        return;
    }
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Settings · Activity log · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Activity log</h1>
                <p class="mt-1 text-[13px] text-muted-foreground" data-testid="retention">
                    The pages each user opened and when, to retrace a bug report. Kept {{ retentionDays }} days. Addresses and times only.
                </p>
            </div>
            <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin only</Badge>
        </div>

        <Card class="min-w-0 gap-0 py-0">
            <form data-testid="filter-form" class="grid gap-3 border-b border-border p-4 sm:grid-cols-3" @submit.prevent="applyFilters">
                <div class="grid gap-1.5">
                    <Label for="filter-user">User</Label>
                    <NativeSelect id="filter-user" v-model="filters.user_id" data-testid="filter-user_id">
                        <option :value="null">Any user</option>
                        <option v-for="user in userOptions" :key="user.id" :value="user.id">{{ user.username }}</option>
                    </NativeSelect>
                </div>

                <div class="grid gap-1.5">
                    <Label for="filter-from">From</Label>
                    <Input id="filter-from" v-model="filters.date_from" data-testid="filter-date_from" type="date" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="filter-to">To</Label>
                    <Input id="filter-to" v-model="filters.date_to" data-testid="filter-date_to" type="date" />
                </div>

                <div class="col-span-full flex gap-2">
                    <Button type="submit" data-testid="apply-filters">Apply filters</Button>
                    <Button type="button" variant="outline" data-testid="clear-filters" @click="clearFilters">Clear</Button>
                </div>
            </form>

            <Table v-if="visits.data.length" sticky-header class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">User</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Page</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Time (UTC)</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="visit in visits.data" :key="visit.id" :data-testid="`visit-row-${visit.id}`" class="align-top">
                        <TableCell class="px-4 font-medium">
                            {{ visit.user }}
                            <span v-if="visit.actingAs" class="block text-xs font-normal text-destructive" data-testid="acting-as">acting as {{ visit.actingAs }}</span>
                            <span v-if="visit.viewingAs" class="block text-xs font-normal text-muted-foreground" data-testid="viewing-as">viewing as {{ visit.viewingAs }}</span>
                        </TableCell>
                        <TableCell class="max-w-[60ch] font-mono text-xs break-all whitespace-normal" data-testid="visit-url">{{ visit.url }}</TableCell>
                        <TableCell class="px-4 tabular-nums">{{ forumDateTime(visit.visited_at) }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState v-else :icon="PhFootprints" title="No page visits match these filters" class="py-10">
                Widen the date range or clear a filter to see more.
            </EmptyState>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                <span class="tabular-nums">{{ visits.total }} total, page {{ visits.current_page }} of {{ visits.last_page }}</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" data-testid="prev-page" :disabled="!visits.prev_page_url" @click="goTo(visits.prev_page_url)">
                        <PhCaretLeft aria-hidden="true" />Previous
                    </Button>
                    <Button variant="outline" size="sm" data-testid="next-page" :disabled="!visits.next_page_url" @click="goTo(visits.next_page_url)">
                        Next<PhCaretRight aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </Card>
    </div>
</template>
