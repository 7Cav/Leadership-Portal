<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PhArrowLeft, PhArrowSquareOut, PhEnvelopeSimple, PhSignOut, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import CounselingPanel from '@/components/milpac/CounselingPanel.vue';
import MilpacPanel from '@/components/milpac/MilpacPanel.vue';
import StaffPracticesPanel from '@/components/milpac/StaffPracticesPanel.vue';
import TransferList from '@/components/roster/TransferList.vue';
import TimeAgo from '@/components/TimeAgo.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { initialsOf } from '@/lib/initials';
import { makeupPmUrl } from '@/lib/makeupPm';
import { historyCell, RC_STATES, SP_STATES, trooperStatusOf, weekLabel } from '@/lib/roster';

// One trooper's milpac (Stories 6.3 to 6.7): the ID block from the cached Cav roster, the courses and
// qualifications panel, the awards history and the service-record timeline. Everything is shaped on the server (`Milpac\TrooperMilpac`) and drawn here; the
// page decides nothing about who may look, the controller has already gated it. The service record that
// completes the mockup's second column arrives with 6.6.
const props = defineProps({
    trooper: { type: Object, required: true },
    courses: { type: Object, required: true },
    awards: { type: Object, required: true },
    timeline: { type: Object, required: true },
    actions: { type: Array, required: true },
    // `{ username, missedWeeks }` when the viewer may send this trooper a makeup PM, else null.
    makeup: { type: Object, default: null },
    sync: { type: Object, required: true },
    // Their moves between units, newest first (2026-10-27).
    transfers: { type: Array, default: () => [] },
    // `Kpi\AttendanceHistory`: the last 20 Cav weeks, oldest first, each with the section they were in that week.
    attendance: { type: Array, default: () => [] },
    // `Milpac/CounselingHistory`: the DCSs and LORs this viewer may see, with what they may do (#381).
    counseling: { type: Object, default: null },
    // The section practices a staff member attended (#448), with what this viewer may do; null for someone not on a staff.
    staffPractices: { type: Object, default: null },
    // True when the viewer opened their own page and their scope does not reach it (2026-10-28): it is theirs to read,
    // but their unit's roster is not, so the way back to it is left out.
    ownPage: { type: Boolean, default: false },
});

// The attendance history newest first, wherever it was recorded: after a transfer the new leadership sees the weeks
// at the old section too (decided 2026-10-27).
const historyRows = computed(() => {
    const current = props.attendance.at(-1)?.week;

    return props.attendance
        .map((week) => ({ ...week, ...historyCell(week, { current: week.week === current }), current: week.week === current }))
        .reverse();
});
const RC_TONE = { ok: 'success', cn: 'warning', mr: 'info', ex: 'warning', bad: 'danger' };
const SP_TONE = { ok: 'success', mk: 'success', ex: 'warning', bad: 'danger' };

const status = computed(() => trooperStatusOf(props.trooper.status));
// The makeup PM opens the forum's own "start conversation" form, pre-filled; the leader sends it there.
const page = usePage();
const makeupHref = computed(() => (props.makeup ? makeupPmUrl(page.props.forum?.baseUrl, props.makeup.username, props.makeup.missedWeeks) : null));
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

// One colour per timeline event type: courses blue, awards green, and the rank milestone in the gold accent. A type
// this page does not know still gets a neutral dot, and its legend wording and row label come from the server.
const EVENT_DOT = { course: 'bg-info', promotion: 'bg-primary', award: 'bg-success' };
const eventDot = (type) => EVENT_DOT[type] ?? 'bg-muted-foreground';
const typeLabel = computed(() => Object.fromEntries(props.timeline.legend.map((item) => [item.type, item.label])));

const GROUP_DOT = { qualified: 'bg-success', in_progress: 'bg-info', required: 'bg-destructive' };

// Why the ID block may not be current: a run since the last good one did not succeed, or no run has
// succeeded for longer than the server allows (stale with no failed attempt to name).
const staleNotice = computed(() => {
    if (props.sync.lastSuccessAt === null) {
        return 'No Cav roster sync has succeeded yet, so this record may be empty or out of date.';
    }
    if (!props.sync.stale) return null;
    if (!props.sync.lastAttempt) {
        return 'The Cav roster has not synced recently. This is the record from the last successful sync.';
    }

    const outcome = props.sync.lastAttempt?.status === 'partial' ? 'was only partly applied' : 'failed';
    const at = props.sync.lastAttempt?.at ? ` on ${forumDateTime(props.sync.lastAttempt.at)}Z` : '';

    return `The latest Cav roster sync ${outcome}${at}. This is the record from the last successful sync.`;
});
</script>

<template>
    <Head :title="`${trooper.displayName} · 7Cav Leadership Portal`" />

    <!-- The shell's <main> already supplies the gutters and the max width. -->
    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <Link v-if="!ownPage" :href="rosterHref" class="mb-2 inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground" data-testid="back-to-roster">
                    <PhArrowLeft class="size-3.5" aria-hidden="true" />
                    {{ trooper.section ? `Back to ${sectionLabel}` : 'Back to roster' }}
                </Link>
                <h1 class="text-xl font-semibold tracking-tight" data-testid="trooper-name">{{ trooper.displayName }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px] text-muted-foreground" data-testid="trooper-subtitle">
                    <span class="max-w-full truncate">{{ trooper.billet ?? 'No billet on record' }}</span>
                    <span v-if="sectionLabel">· {{ sectionLabel }}</span>
                    <span>· Milpac ID #{{ trooper.cavId }}</span>
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
                        · last synced <span data-testid="last-synced"><TimeAgo :at="sync.lastSuccessAt">{{ forumDateTime(sync.lastSuccessAt) }}Z</TimeAgo></span>
                    </template>
                    <template v-else> · never synced</template>
                </p>
            </div>
        </div>

        <InlineNotice v-if="staleNotice" tone="warning" :icon="PhWarning" role="status" data-testid="stale-notice">{{ staleNotice }}</InlineNotice>

        <!-- Links the server already limited to this viewer's scope. Hiding one is cosmetic (AD-6): its route authorizes the request itself. -->
        <nav v-if="actions.length || makeupHref" aria-label="Leadership actions" class="flex flex-wrap items-center gap-2" data-testid="quick-actions">
            <Button v-for="action in actions" :key="action.key" as-child variant="outline" size="sm">
                <Link :href="action.href" :data-testid="`action-${action.key}`">{{ action.label }}</Link>
            </Button>
            <Button v-if="makeupHref" as-child variant="outline" size="sm">
                <a :href="makeupHref" target="_blank" rel="noopener noreferrer" data-testid="action-makeup-pm">
                    <PhEnvelopeSimple aria-hidden="true" />Send makeup PM<span class="sr-only"> (opens the forum in a new tab)</span>
                </a>
            </Button>
        </nav>
        <!-- The panels stack in one column. -->
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

        <MilpacPanel v-if="attendance.length" title="Attendance history" class="w-full max-w-xl" data-testid="attendance-panel">
            <div v-if="transfers.length" class="border-b border-border px-4 py-3">
                <h3 class="mb-2 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Transfers</h3>
                <TransferList :transfers="transfers" />
            </div>
            <!-- Capped like the service record; a focusable scroll region so the keyboard can reach the rest. -->
            <div class="max-h-96 overflow-y-auto" tabindex="0" aria-label="Attendance by Cav week, newest first">
                <table class="w-full text-left text-[13px]" data-testid="attendance-history">
                    <thead class="sticky top-0 bg-card text-[11px] text-muted-foreground">
                        <tr class="border-b border-border">
                            <th scope="col" class="px-4 py-1.5 font-medium">Week</th>
                            <th scope="col" class="px-2 py-1.5 font-medium">Section</th>
                            <th scope="col" class="px-2 py-1.5 font-medium">Roll call</th>
                            <th scope="col" class="px-2 py-1.5 pr-4 font-medium">Section practice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in historyRows" :key="row.week" class="border-b border-border align-top last:border-0" data-testid="attendance-week">
                            <td class="px-4 py-2 whitespace-nowrap">
                                <span class="font-medium tabular-nums">{{ weekLabel(row.week) }}</span>
                                <span v-if="row.current" class="block text-[11px] text-muted-foreground">This week</span>
                            </td>
                            <td class="px-2 py-2" data-testid="attendance-section">{{ row.section?.label ?? 'None' }}</td>
                            <td class="px-2 py-2"><Badge :variant="RC_TONE[row.rc] ?? 'outline'">{{ RC_STATES[row.rc] }}</Badge></td>
                            <td class="px-2 py-2 pr-4"><Badge :variant="SP_TONE[row.sp] ?? 'outline'">{{ SP_STATES[row.sp] }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </MilpacPanel>

        <StaffPracticesPanel v-if="staffPractices" :trooper-id="trooper.id" :staff-practices="staffPractices" class="w-full max-w-xl" />

        <CounselingPanel v-if="counseling" :trooper-id="trooper.id" :counseling="counseling" class="w-full max-w-xl" />

        <MilpacPanel title="Courses and qualifications" class="w-full max-w-xl" data-testid="courses-panel">
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

        <MilpacPanel title="Service record" class="w-full max-w-xl" data-testid="timeline-panel">
            <template v-if="timeline.legend.length" #count>
                <ul class="flex flex-wrap justify-end gap-3 text-xs text-muted-foreground" data-testid="timeline-legend">
                    <li v-for="item in timeline.legend" :key="item.type" class="flex items-center gap-1.5" :data-testid="`legend-${item.type}`">
                        <span class="size-2 rounded-full" :class="eventDot(item.type)" aria-hidden="true" />
                        {{ item.label }}
                    </li>
                </ul>
            </template>

            <p v-if="!timeline.entries.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="timeline-empty">
                No service record events on file.
            </p>

            <!-- Capped so a long record does not stretch the column; a focusable scroll region so the keyboard can reach the rest. -->
            <ol v-else class="max-h-96 overflow-y-auto" tabindex="0" aria-label="Service record events, newest first" data-testid="timeline-list">
                <li
                    v-for="(entry, index) in timeline.entries"
                    :key="`${entry.type}-${entry.date}-${index}`"
                    class="flex items-start gap-3 border-b border-border px-4 py-2.5 text-[13px] last:border-b-0"
                    :data-testid="`event-${entry.type}`"
                >
                    <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="eventDot(entry.type)" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <time :datetime="entry.date" class="font-mono text-[11px] text-muted-foreground" data-testid="event-date">{{ forumDate(entry.date) }}</time>
                        <p class="font-medium" data-testid="event-title">
                            <span class="sr-only" data-testid="event-type">{{ typeLabel[entry.type] ?? entry.type }}: </span>{{ entry.title }}
                        </p>
                        <p v-if="entry.detail" class="text-xs text-muted-foreground" data-testid="event-detail">{{ entry.detail }}</p>
                    </div>
                </li>
            </ol>
        </MilpacPanel>

        <!-- Read-only history from the Cav roster (AD-5): there is deliberately no way to add, edit or recommend an award here (FR17). -->
        <MilpacPanel title="Awards" class="w-full max-w-xl" data-testid="awards-panel">
            <template v-if="awards.items.length" #count>
                <Badge
                    variant="secondary"
                    class="rounded-full tabular-nums"
                    :aria-label="`${awards.items.length} ${awards.items.length === 1 ? 'award' : 'awards'}`"
                    data-testid="awards-count"
                >
                    {{ awards.items.length }}
                </Badge>
            </template>

            <p v-if="!awards.available" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="awards-unavailable">
                Awards are not synced from the Cav roster yet.
                <a :href="trooper.milpacUrl" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline" data-testid="awards-milpac-link">
                    See them on 7cav.us<span class="sr-only"> (opens in a new tab)</span>.
                </a>
            </p>

            <p v-else-if="!awards.items.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="awards-empty">
                No awards on record.
            </p>

            <ul v-else>
                <li
                    v-for="(award, index) in awards.items"
                    :key="`${award.awardedAt}-${award.name}-${index}`"
                    class="flex items-start gap-3 border-b border-border px-4 py-2.5 text-[13px] last:border-b-0"
                    data-testid="award"
                >
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-success" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        {{ award.name }}
                        <span v-if="award.citation" class="block text-xs text-muted-foreground" data-testid="award-citation">{{ award.citation }}</span>
                    </span>
                    <span v-if="award.awardedAt" class="font-mono text-xs text-muted-foreground" data-testid="award-date">
                        {{ forumDate(award.awardedAt) }}
                    </span>
                </li>
            </ul>
        </MilpacPanel>
    </div>
</template>
