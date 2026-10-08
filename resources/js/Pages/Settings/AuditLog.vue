<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight, PhListMagnifyingGlass, PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { forumDateTime } from '@/lib/forumDate';

// Flat entries + option lists (mirrors `StaffGrants.vue`'s flat-props pattern). `entries` is a
// Laravel paginator serialized as-is — `data` plus the standard pagination metadata — so no
// reshaping happens here.
const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    actorOptions: { type: Array, required: true },
    actionOptions: { type: Array, required: true },
});

const filters = reactive({
    actor_id: props.filters.actor_id ?? null,
    action: props.filters.action ?? null,
    target_type: props.filters.target_type ?? '',
    target_id: props.filters.target_id ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

function applyFilters() {
    router.get('/settings/audit-log', filters, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    filters.actor_id = null;
    filters.action = null;
    filters.target_type = '';
    filters.target_id = '';
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
    <Head title="Settings · Audit log · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Audit log</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">Every audited action, filterable by actor, action, target and date range.</p>
            </div>
            <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin only</Badge>
        </div>

        <Card class="min-w-0 gap-0 py-0">
            <form
                data-testid="filter-form"
                class="grid gap-3 border-b border-border p-4 sm:grid-cols-3 lg:grid-cols-6"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-1.5">
                    <Label for="filter-actor">Actor</Label>
                    <NativeSelect id="filter-actor" v-model="filters.actor_id" data-testid="filter-actor_id">
                        <option :value="null">Any actor</option>
                        <option v-for="actor in actorOptions" :key="actor.id" :value="actor.id">{{ actor.username }}</option>
                    </NativeSelect>
                </div>

                <div class="grid gap-1.5">
                    <Label for="filter-action">Action</Label>
                    <NativeSelect id="filter-action" v-model="filters.action" data-testid="filter-action">
                        <option :value="null">Any action</option>
                        <option v-for="action in actionOptions" :key="action" :value="action">{{ action }}</option>
                    </NativeSelect>
                </div>

                <div class="grid gap-1.5">
                    <Label for="filter-target-type">Target type</Label>
                    <Input id="filter-target-type" v-model="filters.target_type" data-testid="filter-target_type" type="text" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="filter-target-id">Target id</Label>
                    <Input id="filter-target-id" v-model="filters.target_id" data-testid="filter-target_id" type="text" />
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

            <Table v-if="entries.data.length" class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Actor</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Action</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Target</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Reason</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Digest</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Time (UTC)</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="entry in entries.data" :key="entry.id" :data-testid="`entry-row-${entry.id}`" class="align-top">
                        <TableCell class="px-4 font-medium">{{ entry.actor }}</TableCell>
                        <TableCell class="font-mono text-xs">{{ entry.action }}</TableCell>
                        <TableCell class="font-mono text-xs">{{ entry.target_type }}#{{ entry.target_id }}</TableCell>
                        <TableCell class="max-w-[28ch] whitespace-normal">
                            <template v-if="entry.reason">{{ entry.reason }}</template>
                            <span v-else class="text-muted-foreground">None given</span>
                        </TableCell>
                        <TableCell class="font-mono text-xs text-muted-foreground" :title="entry.content_digest">{{ entry.content_digest.slice(0, 12) }}…</TableCell>
                        <TableCell class="px-4 tabular-nums">{{ forumDateTime(entry.created_at) }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState v-else :icon="PhListMagnifyingGlass" title="No audit entries match these filters." class="py-10">
                Widen the date range or clear a filter to see more.
            </EmptyState>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                <span class="tabular-nums">{{ entries.total }} total, page {{ entries.current_page }} of {{ entries.last_page }}</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" data-testid="prev-page" :disabled="!entries.prev_page_url" @click="goTo(entries.prev_page_url)">
                        <PhCaretLeft aria-hidden="true" />Previous
                    </Button>
                    <Button variant="outline" size="sm" data-testid="next-page" :disabled="!entries.next_page_url" @click="goTo(entries.next_page_url)">
                        Next<PhCaretRight aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </Card>
    </div>
</template>
