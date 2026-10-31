<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PhChartLine, PhTray, PhUserCheck, PhWarning } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { forumDate, forumDateTime, weekLabel } from '@/lib/forumDate';
import EmptyState from '@/components/EmptyState.vue';
import KpiTiles from '@/components/home/KpiTiles.vue';
import StepLanes from '@/components/home/StepLanes.vue';
import StepMatrix from '@/components/home/StepMatrix.vue';
import SubordinateUnits from '@/components/home/SubordinateUnits.vue';
import NeedsAttention from '@/components/home/NeedsAttention.vue';

// The weekly-cycle home (Story 1.15, mockups/v2/shell-f.html) and the leader's dashboard (Stories
// 5.9 to 5.11). The week bar's calendar comes from HomeController via WeekOf (AD-11); the step
// lanes and matrix, worklist, KPI tiles and unit table are the viewer's scope as the server read
// it. Where the scope or the data is missing each area is an honest empty state; nothing here
// shows a number the portal does not have.
const props = defineProps({
    // `{ start, reportDue }`, ISO-8601 UTC. `start` is the Saturday 00:00Z that opens the week.
    week: { type: Object, required: true },
    // The six KPIs for the viewer's scope with their sent-snapshot history (Story 5.9); null when
    // the viewer's scope holds no units.
    kpis: { type: Object, default: null },
    // What needs the viewer's attention (Story 5.10): `{ items, totals }`; null without a scope.
    attention: { type: Object, default: null },
    // The direct subordinate units of the viewer's scope with KPIs and report status (Story 5.11).
    units: { type: Array, default: () => [] },
    // The week's five steps per section in scope (the step lanes and matrix).
    steps: { type: Array, default: () => [] },
    // The viewer's own staff report while it is due and not sent, `{ week, due, href }`, or null.
    staffReport: { type: Object, default: null },
});

const page = usePage();

// Why enrichment produced nothing this login (Story 1.7). `not_on_roster` is a normal, expected
// population (a trooper without a milpac), not a Cav outage, and warning them on every login
// would bury the case this banner exists for.
const profileUnavailable = computed(() => {
    const reason = page.props.auth?.profileUnavailable ?? null;

    return reason && reason !== 'not_on_roster' ? reason : null;
});

const DAY = 24 * 60 * 60 * 1000;
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Ticks the countdown and the "today" marker. Thirty seconds is plenty for a minutes display.
const now = ref(Date.now());
let timer = null;
let reloading = false;

// `week` is fixed at page load. Once its deadline passes, the server has moved on to the next
// cycle, so fetch it (once, until that visit finishes) instead of counting down a stale week.
// Every prop on this page is that week's (the steps, worklist, KPIs and unit table), so all of
// them are reloaded, never the week bar alone over last cycle's numbers.
function tick() {
    now.value = Date.now();

    if (!reloading && now.value >= new Date(props.week.reportDue).getTime()) {
        reloading = true;
        router.reload({ onFinish: () => (reloading = false) });
    }
}

onMounted(() => {
    timer = setInterval(tick, 30_000);
});
onBeforeUnmount(() => clearInterval(timer));

const weekStart = computed(() => new Date(props.week.start).getTime());

// Sat → Fri, plus the Saturday the report is due on: the eight columns of the mockup's week bar.
const days = computed(() =>
    Array.from({ length: 8 }, (_, index) => {
        const at = new Date(weekStart.value + index * DAY);

        return { index, weekday: WEEKDAYS[at.getUTCDay()], date: forumDate(at), day: String(at.getUTCDate()).padStart(2, '0') };
    }),
);

// Days are whole UTC days from the week start, matching the server's Saturday 00:00Z boundary.
const todayIndex = computed(() => Math.floor((now.value - weekStart.value) / DAY));

const dueLabel = computed(() => {
    const due = new Date(props.week.reportDue);
    const time = `${String(due.getUTCHours()).padStart(2, '0')}:${String(due.getUTCMinutes()).padStart(2, '0')}Z`;

    return `${WEEKDAYS[due.getUTCDay()]} ${forumDate(due)} ${time}`;
});

const countdown = computed(() => {
    let seconds = Math.floor((new Date(props.week.reportDue).getTime() - now.value) / 1000);
    if (seconds <= 0) return 'Deadline passed';

    const d = Math.floor(seconds / 86400);
    seconds -= d * 86400;
    const h = Math.floor(seconds / 3600);
    seconds -= h * 3600;
    const m = Math.floor(seconds / 60);

    return `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m left`;
});

</script>

<template>
    <Head title="Home · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <!--
          Degraded-mode notice (Story 1.7, AC #2): distinct from a login error, since a Cav outage
          never breaks the login itself. No dismiss control: it stays until enrichment succeeds on
          a later login (AD-20, NFR8).
        -->
        <div
            v-if="profileUnavailable"
            role="alert"
            class="flex items-start gap-2.5 rounded-lg border border-warning/35 bg-warning/8 px-4 py-3 text-[13px] leading-normal"
        >
            <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <p>
                Roster enrichment is unavailable right now, so your rank, billet and MOS are missing for this session.
                They return the next time you sign in while the Cav roster is reachable.
            </p>
        </div>

        <!-- Week bar: the Cav week, Saturday to Friday, and the countdown to the report. -->
        <Card role="region" class="gap-0 overflow-hidden py-0" aria-labelledby="week-title" data-testid="week-bar">
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3 px-4 pt-4 pb-3 sm:px-5">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Weekly cycle</p>
                    <h1 id="week-title" class="text-xl font-semibold tracking-tight sm:text-[22px]">Week Ending {{ weekLabel(week.start) }}</h1>
                </div>
                <div class="w-full sm:ml-auto sm:w-auto sm:text-right">
                    <p class="text-xs text-muted-foreground">Report due {{ dueLabel }}</p>
                    <p class="text-lg leading-tight font-semibold tabular-nums" data-testid="countdown">{{ countdown }}</p>
                </div>
            </div>

            <!-- Phone: one compact row of days. -->
            <ol class="grid grid-cols-8 gap-1 border-t border-border px-4 py-3 text-center text-[11px] sm:hidden" aria-label="Days of the week">
                <li
                    v-for="day in days"
                    :key="day.index"
                    class="rounded-md py-1"
                    :class="{
                        'bg-primary font-semibold text-primary-foreground': day.index === todayIndex,
                        'text-muted-foreground': day.index < todayIndex,
                        'shadow-[inset_0_0_0_1px_var(--primary)]': day.index === 7 && todayIndex !== 7,
                    }"
                    :aria-current="day.index === todayIndex ? 'date' : undefined"
                >
                    <span class="block">{{ day.weekday }}</span>
                    <span class="block tabular-nums">{{ day.day }}</span>
                </li>
            </ol>

            <!-- Wider screens: the day grid the step lanes will run across. -->
            <div class="hidden border-t border-border sm:block">
                <div class="grid grid-cols-[9.5rem_repeat(7,minmax(0,1fr))_minmax(0,0.9fr)] text-xs">
                    <div class="flex items-end px-5 py-2 text-muted-foreground">Step</div>
                    <div
                        v-for="day in days"
                        :key="day.index"
                        class="border-l border-border px-2.5 py-2"
                        :class="{ 'bg-primary/7': day.index === todayIndex }"
                        :aria-current="day.index === todayIndex ? 'date' : undefined"
                    >
                        <div class="flex items-center gap-1.5" :class="day.index === todayIndex ? 'font-semibold' : 'font-medium'">
                            {{ day.weekday }}
                            <Badge v-if="day.index === todayIndex">Today</Badge>
                        </div>
                        <div class="text-muted-foreground tabular-nums">{{ day.date }}<template v-if="day.index === 7"> due</template></div>
                    </div>
                </div>
            </div>

            <StepLanes :steps="steps" />
        </Card>

        <!-- A platoon or company HQ staff member's own staff report, until they send it. -->
        <div
            v-if="staffReport"
            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-border bg-card px-4 py-3 text-[13px]"
            data-testid="home-staff-report"
        >
            <PhUserCheck class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <p class="min-w-0 flex-1">
                Your staff report for the week ending {{ weekLabel(staffReport.week + 'T00:00:00Z') }} is not sent yet. It is due
                {{ forumDateTime(staffReport.due) }}Z.
            </p>
            <Button size="sm" variant="outline" as-child>
                <Link :href="staffReport.href" data-testid="home-staff-report-link">Open staff report</Link>
            </Button>
        </div>

        <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,0.62fr)_minmax(0,1fr)]">
            <NeedsAttention v-if="attention" :attention="attention" />
            <Card v-else role="region" class="min-w-0 gap-0 py-0" aria-labelledby="attention-title">
                <div class="flex items-center gap-2 px-4 pt-3.5">
                    <h2 id="attention-title" class="text-[15px] font-semibold">Needs attention</h2>
                </div>
                <EmptyState :icon="PhTray" title="No units in your scope yet" class="py-12">
                    Reports holding up the chain and at-risk troopers show here once a unit is assigned to you.
                </EmptyState>
            </Card>

            <StepMatrix :steps="steps" />
        </div>

        <section aria-labelledby="trends-title">
            <h2 id="trends-title" class="mb-2 text-[15px] font-semibold">Last 30 days</h2>
            <KpiTiles v-if="kpis" :kpis="kpis" />
            <Card v-else class="gap-0 py-0">
                <EmptyState :icon="PhChartLine" title="No units in your scope yet" class="py-10">
                    KPIs for your command appear here once a unit is assigned to you.
                </EmptyState>
            </Card>
        </section>

        <SubordinateUnits :units="units" />
    </div>
</template>
