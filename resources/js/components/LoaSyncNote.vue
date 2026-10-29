<script setup>
import { computed } from 'vue';
import { PhCalendarBlank, PhWarning } from '@phosphor-icons/vue';
import { forumDate, forumDateTime } from '@/lib/forumDate';

// Where the leave data behind a screen's numbers comes from and when it last synced (Story 4.3, AD-20), for the
// screens whose figures move with leave (on LOA, at-risk, the LOA weeks) but that draw no `LoaBadge` per trooper.
// `sync` is `LoaSyncState::toArray()`, the same the badge's popover reads, so both say the same thing. A failed,
// overdue or missing sync is said in words, with a warning icon, so a stale on-LOA count is never read as current.
const props = defineProps({
    sync: { type: Object, required: true },
});

// The latest finished run was a failure or only partial: the stale state names that rather than "overdue".
const failed = computed(() => Boolean(props.sync.latestStatus) && props.sync.latestStatus !== 'success');
const problem = computed(() => props.sync.stale || props.sync.neverSynced);

// While the sync's first read of the year is still going back through older posts (a few pages each run), say how far
// back it has got. Recent leave is read first, so this is a note, not a warning: only older weeks can miss leave.
const backfill = computed(() => {
    if (!props.sync.backfilling) return null;

    return props.sync.backfilledTo
        ? `Older leave is still being read, back to ${forumDate(props.sync.backfilledTo)} so far`
        : 'Older leave is still being read';
});

const status = computed(() => {
    const { source, lastSyncedAt } = props.sync;
    const at = lastSyncedAt ? `${forumDateTime(lastSyncedAt)}Z` : null;

    if (!at) {
        return failed.value
            ? `Leave from ${source}: the sync failed and has never succeeded, so nobody shows as on LOA`
            : `Leave from ${source} has not synced yet, so nobody shows as on LOA`;
    }
    if (!props.sync.stale) return `Leave from ${source}, synced ${at}`;

    return failed.value
        ? `Leave from ${source}: the latest sync did not succeed, so on LOA and at-risk use the last good sync, ${at}`
        : `Leave from ${source}, last synced ${at}, overdue. On LOA and at-risk may be out of date`;
});

const text = computed(() => (backfill.value ? `${status.value}. ${backfill.value}` : status.value));
</script>

<template>
    <p
        class="flex items-center gap-1.5 text-xs"
        :class="problem ? 'text-warning' : 'text-muted-foreground'"
        :role="problem ? 'status' : undefined"
        :data-state="problem ? 'problem' : 'ok'"
        data-testid="loa-sync"
    >
        <component :is="problem ? PhWarning : PhCalendarBlank" class="size-3.5 shrink-0" aria-hidden="true" />
        {{ text }}
    </p>
</template>
