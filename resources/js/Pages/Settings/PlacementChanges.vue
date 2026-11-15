<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { PhArrowsLeftRight, PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import OrgStructureTabs from '@/components/settings/OrgStructureTabs.vue';
import { forumDate, forumZulu } from '@/lib/forumDate';

// Placement changes: every move between units the roster sync or an Admin wrote over the last days, newest first, so a
// wrong milpac edit is caught the day it happens. Each change is `{ at, trooperId, trooper, from, to, source,
// reversedAt, reverses }` (`Milpac\PlacementHistory::changesSince()`): `reversedAt` is when the move was undone, and
// `reverses` marks the move that undid one.
const props = defineProps({
    changes: { type: Array, required: true },
    days: { type: Number, required: true },
    spans: { type: Array, required: true },
});

const SOURCES = { sync: 'Roster sync', manual: 'Admin' };

function move(change) {
    if (change.from && change.to) return `${change.from} to ${change.to}`;
    if (change.to) return `Placed in ${change.to}`;

    return `Left ${change.from}`;
}

function note(change) {
    if (change.reversedAt) return `Moved back ${forumDate(change.reversedAt)}. Likely a wrong milpac edit`;
    if (change.reverses) return 'Undoes the move before. Likely a milpac correction';

    return null;
}

const show = (days) => router.get('/settings/placement-changes', { days }, { preserveScroll: true });
</script>

<template>
    <Head title="Settings · Org structure · Placement changes · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Org structure</h1>
                <p class="mt-1 max-w-[70ch] text-[13px] text-muted-foreground">
                    Every move between units the roster sync or an Admin made, newest first. A trooper moved and put straight back is marked: that is usually
                    a wrong edit on the milpac, worth reporting to S1.
                </p>
            </div>
            <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin only</Badge>
        </div>

        <OrgStructureTabs current="changes" />

        <Card class="min-w-0 gap-0 py-0">
            <div class="flex flex-wrap items-center gap-2 border-b border-border p-4 text-[13px]" role="group" aria-label="How far back">
                <span class="text-muted-foreground">Last</span>
                <button
                    v-for="span in [...spans].sort((a, b) => a - b)"
                    :key="span"
                    type="button"
                    class="rounded-md border px-2.5 py-1 tabular-nums"
                    :class="span === days ? 'border-primary bg-primary/10 font-medium' : 'border-border text-muted-foreground hover:text-foreground'"
                    :aria-pressed="span === days"
                    :data-testid="`span-${span}`"
                    @click="show(span)"
                >
                    {{ span }} days
                </button>
            </div>

            <Table v-if="changes.length" sticky-header class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Time (Zulu)</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Trooper</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Move</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">By</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="(change, index) in changes"
                        :key="`${change.trooperId}-${change.at}-${index}`"
                        class="align-top"
                        :class="(change.reversedAt || change.reverses) && 'bg-warning/8'"
                        data-testid="placement-change"
                    >
                        <TableCell class="px-4 tabular-nums">{{ forumZulu(change.at) }}</TableCell>
                        <TableCell class="font-medium">
                            <Link :href="`/troopers/${change.trooperId}`" class="underline-offset-2 hover:underline">{{ change.trooper }}</Link>
                        </TableCell>
                        <TableCell class="whitespace-normal">
                            {{ move(change) }}
                            <span v-if="note(change)" class="block text-xs text-muted-foreground" data-testid="change-note">{{ note(change) }}</span>
                        </TableCell>
                        <TableCell class="px-4 text-muted-foreground">{{ SOURCES[change.source] ?? 'Unknown' }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState v-else :icon="PhArrowsLeftRight" :title="`No placement changes in the last ${days} days`" class="py-10" />
        </Card>
    </div>
</template>
