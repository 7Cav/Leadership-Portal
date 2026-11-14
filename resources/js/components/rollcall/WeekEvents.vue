<script setup>
import { computed } from 'vue';
import { forumDateTime } from '@/lib/forumDate';

// The week's events on the 7Cav calendar for the section's game (#751), as the post prints them with `{{events}}`.
// Only listed: nothing here marks anyone as having been at one. Read by the server from the calendar it keeps (the
// `events` prop, `Rollcall\WeekEvents`), and as the report froze them once it is sent.
const props = defineProps({
    // `{ state: 'listed' | 'no_game' | 'unavailable', game, items: [{ name, start, url }], readAt, lastReadFailed, frozen }`.
    events: { type: Object, required: true },
});

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** An event's start as the page writes a moment in Zulu: `Sat 21NOV26 1900Z`. */
function startLabel(start) {
    return `${WEEKDAYS[new Date(start).getUTCDay()]} ${forumDateTime(start)}Z`;
}

const items = computed(() => (props.events.state === 'listed' ? (props.events.items ?? []) : []));

// Why there is nothing to list, or null when there is.
const empty = computed(() => {
    if (props.events.state === 'no_game') return 'This section plays no game, so no calendar events are listed for it.';
    if (props.events.state === 'unavailable') return 'The 7Cav calendar could not be read, so no events are listed.';
    if (items.value.length === 0) return `No ${props.events.game} events on the 7Cav calendar this week.`;

    return null;
});

// A list kept from before the last read failed may be missing a change made since.
const stale = computed(() =>
    props.events.state === 'listed' && !props.events.frozen && props.events.lastReadFailed && props.events.readAt
        ? `The calendar could not be read at the last try. Listed as read ${forumDateTime(props.events.readAt)}Z.`
        : null,
);
</script>

<template>
    <section class="rounded-lg border border-border bg-card px-4 py-3 text-[13px]" aria-labelledby="week-events-title" data-testid="week-events">
        <h2 id="week-events-title" class="text-[13px] font-semibold">Events this week</h2>
        <p v-if="empty" class="mt-1 text-xs text-muted-foreground" data-testid="week-events-empty">{{ empty }}</p>
        <ul v-else class="mt-1.5 divide-y divide-border" data-testid="week-events-list">
            <li v-for="event in items" :key="`${event.start} ${event.name}`" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5">
                <span class="shrink-0 font-medium tabular-nums" data-testid="week-event-start">{{ startLabel(event.start) }}</span>
                <a v-if="event.url" :href="event.url" target="_blank" rel="noopener noreferrer" class="min-w-0 break-words underline-offset-2 hover:underline" data-testid="week-event-name">{{
                    event.name
                }}</a>
                <span v-else class="min-w-0 break-words" data-testid="week-event-name">{{ event.name }}</span>
            </li>
        </ul>
        <p v-if="events.frozen" class="mt-1.5 text-xs text-muted-foreground" data-testid="week-events-frozen">As the report kept them when it was sent.</p>
        <p v-else-if="stale" class="mt-1.5 text-xs text-muted-foreground" data-testid="week-events-stale">{{ stale }}</p>
    </section>
</template>
