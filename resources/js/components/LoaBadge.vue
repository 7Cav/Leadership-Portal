<script setup>
import { computed, useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { PhWarning } from '@phosphor-icons/vue';
import { forumDate, forumDateTime } from '@/lib/forumDate';

// The one inline LOA badge (Story 4.5): rollcall, the section overview and the trooper page all render it, so
// none invents its own rules. It only draws what it is given. `cover` is one trooper's `LoaCover::toArray()`
// for the week (Story 4.4) and `sync` is `LoaSyncState::toArray()` (Story 4.3); it decides nothing about who is
// on leave. A trooper with no leave that week shows nothing at all.
//
// A leave that needs review never shows a date or says the trooper is away: the parser could not establish
// either, so the badge says only that someone must look (AD-4). The source and last-sync time (AD-20), the dates
// and the review reason open in a popover on tap, click or keyboard (a tooltip never opens on touch), and are
// always there for screen readers. Hovering the badge also names its end date in a tooltip, a shortcut for a
// mouse user that the popover still covers on touch.
//
// It is the stock Badge, unrestyled. The three states differ by more than hue: a covered leave is the plain
// warning badge, a leave that needs review is outlined, dashed and muted with a warning icon, and an overdue
// ELOA, the one a leader must chase, is the danger badge with the icon.
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

// An ELOA runs until a return is posted, so one still open after its expected return date is overdue, not over:
// the pill says so, so a leader can chase it. The server decides (on its UTC day, AD-11), not this device's clock.
const overdue = computed(() => covered.value && props.cover.overdue === true);

// A leave about to end counts down ("ends in 2d", as the section overview mockup draws it) and anything
// further off names its date. The server counts the days, on its UTC day, like overdue.
const COUNTDOWN_DAYS = 7;

const label = computed(() => {
    if (needsReview.value) return `${typeLabel.value} needs review`;
    // A leave that is already over says so, in the past tense: the server knows the day, this device does not.
    if (props.cover.ended === true && props.cover.endsAt) return `${typeLabel.value} ended ${forumDate(props.cover.endsAt)}`;
    if (overdue.value) return `${typeLabel.value} overdue ${forumDate(props.cover.expectedReturnAt)}`;

    const days = props.cover.endsInDays;
    if (typeof days === 'number' && days <= COUNTDOWN_DAYS) {
        return days === 0 ? `${typeLabel.value} ends today` : `${typeLabel.value} ends in ${days}d`;
    }

    return endDate.value ? `${typeLabel.value} ends ${forumDate(endDate.value)}` : `${typeLabel.value} ongoing`;
});

// The hover tooltip: the label's date spelled out, so "LOA ends in 2d" can be read as a day without opening the
// details ("LOA until 20OCT26"). Mouse only; a tap still opens the full details above.
const hoverText = computed(() => {
    const type = typeLabel.value;

    if (needsReview.value) return `${type} needs review: the leave post could not be read, so no dates are shown`;
    if (props.cover.ended === true && props.cover.endsAt) return `${type} ended ${forumDate(props.cover.endsAt)}`;
    if (overdue.value) return `${type} overdue, expected back ${forumDate(props.cover.expectedReturnAt)}, no return posted yet`;
    if (props.cover.endsAt) return `${type} until ${forumDate(props.cover.endsAt)}`;
    if (props.cover.expectedReturnAt) return `${type}, expected back ${forumDate(props.cover.expectedReturnAt)}`;

    return `${type}, no return date yet`;
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
            lines.push(
                overdue.value
                    ? 'That date has passed and no return has been posted. It runs until one is.'
                    : 'It runs until a return is posted, and can be extended.',
            );
        } else {
            lines.push(`${start}, no end date yet`);
        }
    } else {
        lines.push('The leave post could not be read, so no dates are shown.');
        if (props.cover.reviewReason) lines.push(props.cover.reviewReason);
    }

    if (props.sync) {
        lines.push(`Source: ${props.sync.source}`);
        lines.push(props.sync.lastSyncedAt ? `Last synced ${forumDateTime(props.sync.lastSyncedAt)}Z` : 'Never synced');
        if (props.sync.stale) lines.push('The latest sync did not succeed, so this is the last good data.');
    }

    return lines;
});

const variant = computed(() => {
    if (needsReview.value) return 'outline';

    return overdue.value ? 'danger' : 'warning';
});

// An icon on the two states that need a person, so colour is not the only cue.
const needsAction = computed(() => needsReview.value || overdue.value);

const detailId = useId();
</script>

<template>
    <span v-if="visible" class="inline-flex">
        <TooltipProvider :delay-duration="200">
            <Popover>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <PopoverTrigger as-child>
                            <Badge
                                as="button"
                                type="button"
                                :variant="variant"
                                :class="needsReview ? 'cursor-pointer border-dashed' : 'cursor-pointer'"
                                :data-state-kind="cover.state"
                                :aria-describedby="detailId"
                                data-testid="loa-badge"
                            >
                                <PhWarning v-if="needsAction" weight="bold" aria-hidden="true" />
                                {{ label }}
                            </Badge>
                        </PopoverTrigger>
                    </TooltipTrigger>
                    <TooltipContent data-testid="loa-tooltip">{{ hoverText }}</TooltipContent>
                </Tooltip>
                <PopoverContent class="space-y-0.5 text-xs" data-testid="loa-popover">
                    <p v-for="(line, index) in details" :key="index">{{ line }}</p>
                </PopoverContent>
            </Popover>
        </TooltipProvider>
        <span :id="detailId" class="sr-only" data-testid="loa-details">{{ details.join('. ') }}</span>
    </span>
</template>
