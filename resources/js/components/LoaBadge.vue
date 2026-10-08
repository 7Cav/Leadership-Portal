<script setup>
import { computed, useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { forumDate, forumDateTime } from '@/lib/forumDate';

// The one inline LOA badge (Story 4.5): rollcall, the section overview and the trooper page all render it, so
// none invents its own rules. It only draws what it is given. `cover` is one trooper's `LoaCover::toArray()`
// for the week (Story 4.4) and `sync` is `LoaSyncState::toArray()` (Story 4.3); it decides nothing about who is
// on leave. A trooper with no leave that week shows nothing at all.
//
// A leave that needs review never shows a date or says the trooper is away: the parser could not establish
// either, so the badge says only that someone must look (AD-4). The source and last-sync time (AD-20) are on
// hover and focus, and for screen readers.
const props = defineProps({
    cover: { type: Object, default: null },
    sync: { type: Object, default: null },
});

const covered = computed(() => props.cover?.state === 'covered');
const needsReview = computed(() => props.cover?.state === 'needs_review');
const visible = computed(() => covered.value || needsReview.value);

const typeLabel = computed(() => props.cover?.typeLabel ?? 'LOA');

// The day the pill names: a leave's end, or for an ELOA still open, the return it expects. An ELOA is not ended
// by that date (it can be extended, and ends when a return is posted), so it is only shown.
const endDate = computed(() => props.cover?.endsAt ?? props.cover?.expectedReturnAt ?? null);

const label = computed(() => {
    if (needsReview.value) return `${typeLabel.value} needs review`;

    return endDate.value ? `${typeLabel.value} ends ${forumDate(endDate.value)}` : `${typeLabel.value} ongoing`;
});

// What the label cannot fit: the dates read from the post, or why it could not be read, then where the data
// comes from and how current it is. A failed latest sync says the cache shown is the last good one.
const details = computed(() => {
    const lines = [];

    if (covered.value) {
        const start = props.cover.startsAt ? `${typeLabel.value} from ${forumDate(props.cover.startsAt)}` : typeLabel.value;

        if (props.cover.endsAt) {
            lines.push(`${start} to ${forumDate(props.cover.endsAt)}`);
        } else if (props.cover.expectedReturnAt) {
            lines.push(`${start}, expected return ${forumDate(props.cover.expectedReturnAt)}`);
            lines.push('It runs until a return is posted, and can be extended.');
        } else {
            lines.push(`${start}, no end date yet`);
        }
    } else {
        lines.push('The leave post could not be read, so no dates are shown.');
        if (props.cover.reviewReason) lines.push(props.cover.reviewReason);
    }

    if (props.sync) {
        lines.push(`Source: ${props.sync.source}`);
        lines.push(props.sync.lastSyncedAt ? `Last synced ${forumDateTime(props.sync.lastSyncedAt)} UTC` : 'Never synced');
        if (props.sync.stale) lines.push('The latest sync did not succeed, so this is the last good data.');
    }

    return lines;
});

const detailId = useId();
</script>

<template>
    <span v-if="visible" class="inline-flex">
        <TooltipProvider :delay-duration="150">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Badge
                        :variant="covered ? 'warning' : 'outline'"
                        :class="needsReview ? 'border-dashed text-warning' : ''"
                        :data-state-kind="cover.state"
                        :aria-describedby="detailId"
                        tabindex="0"
                        data-testid="loa-badge"
                    >
                        {{ label }}
                    </Badge>
                </TooltipTrigger>
                <TooltipContent class="max-w-72 space-y-0.5">
                    <p v-for="line in details" :key="line">{{ line }}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <span :id="detailId" class="sr-only" data-testid="loa-details">{{ details.join('. ') }}</span>
    </span>
</template>
