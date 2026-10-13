<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowLeft, PhArrowSquareOut, PhSignOut, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import MilpacPanel from '@/components/milpac/MilpacPanel.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { initialsOf } from '@/lib/initials';
import { trooperStatusOf } from '@/lib/roster';

// One trooper's milpac (Stories 6.3, 6.4): the ID block from the cached Cav roster and the courses and
// qualifications panel. Everything is shaped on the server (`Milpac\TrooperMilpac`) and drawn here; the
// page decides nothing about who may look, the controller has already gated it. The service record that
// completes the mockup's second column arrives with 6.6.
const props = defineProps({
    trooper: { type: Object, required: true },
    courses: { type: Object, required: true },
    sync: { type: Object, required: true },
});

const status = computed(() => trooperStatusOf(props.trooper.status));
// The avatar's letters, as the mockup draws them: the surname's first letter and the given initial ("Milkovic.D"
// is MD).
const initials = computed(() => initialsOf(props.trooper.username) || '?');
// Back to the roster of the section the trooper sits in, or the roster's own landing when they have none.
const rosterHref = computed(() => (props.trooper.section ? `/roster/${props.trooper.section.id}` : '/roster'));
const sectionLabel = computed(() => {
    const section = props.trooper.section;
    if (!section) return null;

    return section.callsign ? `${section.callsign} ${section.name}` : section.name;
});

// "2y 3m", "7m", or "under a month": how long, from a whole-month count.
function duration(months) {
    if (months === null || months === undefined) return 'Unknown';
    if (months < 1) return 'Under a month';

    const years = Math.floor(months / 12);
    const rest = months % 12;

    return [years ? `${years}y` : null, rest ? `${rest}m` : null].filter(Boolean).join(' ');
}

const GROUP_DOT = { qualified: 'bg-success', in_progress: 'bg-info', required: 'bg-destructive' };

// Why the ID block may not be current: a run since the last good one did not succeed.
const staleNotice = computed(() => {
    if (props.sync.lastSuccessAt === null) {
        return 'No Cav roster sync has succeeded yet, so this record may be empty or out of date.';
    }
    if (!props.sync.stale) return null;

    const outcome = props.sync.lastAttempt?.status === 'partial' ? 'was only partly applied' : 'failed';
    const at = props.sync.lastAttempt?.at ? ` on ${forumDateTime(props.sync.lastAttempt.at)} UTC` : '';

    return `The latest Cav roster sync ${outcome}${at}. This is the record from the last successful sync.`;
});
</script>

<template>
    <Head :title="trooper.displayName" />

    <!-- The shell's <main> already supplies the gutters and the max width. -->
    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <Link :href="rosterHref" class="mb-2 inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground" data-testid="back-to-roster">
                    <PhArrowLeft class="size-3.5" aria-hidden="true" />
                    {{ trooper.section ? `Back to ${sectionLabel}` : 'Back to roster' }}
                </Link>
                <h1 class="text-xl font-semibold tracking-tight" data-testid="trooper-name">{{ trooper.displayName }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px] text-muted-foreground" data-testid="trooper-subtitle">
                    <span class="max-w-full truncate">{{ trooper.billet ?? 'No billet on record' }}</span>
                    <span v-if="sectionLabel">· {{ sectionLabel }}</span>
                    <span>· Profile #{{ trooper.cavId }}</span>
                    <span class="inline-flex items-center gap-1">
                        ·
                        <a
                            :href="trooper.milpacUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary hover:underline"
                            data-testid="milpac-link"
                        >
                            View on 7cav.us <PhArrowSquareOut class="size-3" aria-hidden="true" /><span class="sr-only">(opens in a new tab)</span>
                        </a>
                    </span>
                </p>
                <p data-testid="source-line" class="mt-1 text-[13px] text-muted-foreground">
                    Source: {{ sync.source }}
                    <template v-if="sync.lastSuccessAt">
                        · last synced <span data-testid="last-synced">{{ forumDateTime(sync.lastSuccessAt) }} UTC</span>
                    </template>
                    <template v-else> · never synced</template>
                </p>
            </div>
        </div>

        <InlineNotice v-if="staleNotice" tone="warning" :icon="PhWarning" role="status" data-testid="stale-notice">{{ staleNotice }}</InlineNotice>

        <!-- One column until the service record (6.6) arrives to fill a second. -->
        <Card class="w-full max-w-xl gap-0 overflow-hidden py-0" data-testid="id-block">
                <div class="flex flex-col items-center gap-1 px-5 pt-6 pb-5 text-center">
                    <Avatar class="mb-2 size-16 border-2 border-primary">
                        <AvatarFallback class="bg-transparent text-lg font-semibold text-primary" aria-hidden="true">{{ initials }}</AvatarFallback>
                    </Avatar>
                    <!-- The page's h1 is the name; this card's heading is the billet, so a screen reader does not hear the name twice. -->
                    <h2 class="text-base font-semibold text-primary" data-testid="billet">{{ trooper.billet ?? 'No billet on record' }}</h2>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="trooper.tis">Joined <span data-testid="joined">{{ forumDate(trooper.tis) }}</span> · </template>
                        MOS <span data-testid="mos">{{ trooper.mos ?? 'None' }}</span>
                    </p>
                    <Badge :variant="status.variant" class="mt-2" data-testid="status"><PhSignOut v-if="trooper.departed" aria-hidden="true" />{{ status.label }}</Badge>
                </div>

                <dl class="grid grid-cols-2 border-t border-border text-[13px]">
                    <div class="flex flex-col gap-0.5 border-r border-border px-4 py-3">
                        <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Time in service</dt>
                        <dd class="font-medium tabular-nums" data-testid="tis">{{ duration(trooper.tisMonths) }}</dd>
                    </div>
                    <div class="flex flex-col gap-0.5 px-4 py-3">
                        <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Time in grade</dt>
                        <dd class="font-medium tabular-nums" data-testid="tig">{{ duration(trooper.tigMonths) }}</dd>
                    </div>
                    <div class="col-span-2 flex flex-col gap-0.5 border-t border-border px-4 py-3">
                        <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Rank</dt>
                        <dd class="font-medium" data-testid="rank">{{ trooper.rank ?? 'None' }}</dd>
                    </div>
                    <div class="col-span-2 flex flex-col gap-0.5 border-t border-border px-4 py-3">
                        <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Primary section</dt>
                        <dd class="font-medium" data-testid="section">
                            <template v-if="sectionLabel">{{ sectionLabel }}</template>
                            <span v-else class="text-muted-foreground" data-testid="unmapped">Not placed in a section yet</span>
                        </dd>
                    </div>
                </dl>
        </Card>

        <MilpacPanel title="Courses &amp; qualifications" class="w-full max-w-xl" data-testid="courses-panel">
            <template v-if="courses.total" #count>
                <!-- A progress count, not a warning: neutral, and named so a screen reader hears what it counts. -->
                <Badge
                    variant="secondary"
                    class="rounded-full tabular-nums"
                    :aria-label="`${courses.qualified} of ${courses.total} qualified`"
                    data-testid="courses-count"
                >
                    {{ courses.qualified }} / {{ courses.total }}
                </Badge>
            </template>

            <p v-if="!courses.groups.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="courses-empty">
                No courses on record.
            </p>

            <section v-for="group in courses.groups" :key="group.status" :data-testid="`group-${group.status}`">
                <h3 class="border-b border-border bg-muted/30 px-4 py-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                    {{ group.label }}
                </h3>
                <ul>
                    <li
                        v-for="course in group.courses"
                        :key="course.code"
                        class="flex items-center gap-3 border-b border-border px-4 py-2.5 text-[13px] last:border-b-0"
                        data-testid="course"
                    >
                        <span class="size-2 shrink-0 rounded-full" :class="GROUP_DOT[group.status]" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            {{ course.name }}
                            <span class="ml-1 font-mono text-xs text-muted-foreground">{{ course.code }}</span>
                        </span>
                        <span v-if="course.awardedAt" class="font-mono text-xs text-muted-foreground" data-testid="awarded">
                            {{ forumDate(course.awardedAt) }}
                        </span>
                    </li>
                </ul>
            </section>
        </MilpacPanel>
    </div>
</template>
