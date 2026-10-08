<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { PhChartLine, PhClockCountdown, PhTray, PhUsersThree, PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Table, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { forumDate } from '@/lib/forumDate';
import EmptyState from '@/components/EmptyState.vue';

// The weekly-cycle home (Story 1.15, mockups/v2/shell-f.html). The week bar is real: it is only
// the calendar, from HomeController via WeekOf (AD-11). Every other area is an honest empty state
// until its story feeds it data; nothing here shows a number the portal does not have.
const props = defineProps({
    // `{ start, reportDue }`, ISO-8601 UTC. `start` is the Saturday 00:00Z that opens the week.
    week: { type: Object, required: true },
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
onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 30_000);
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
    if (seconds <= 0) return 'Due now';

    const d = Math.floor(seconds / 86400);
    seconds -= d * 86400;
    const h = Math.floor(seconds / 3600);
    seconds -= h * 3600;
    const m = Math.floor(seconds / 60);

    return `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m left`;
});

const STEPS = ['Rollcall posted', 'Rollcall signed', 'SP held', 'Attendance marked', 'Report sent'];
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
                    <h1 id="week-title" class="text-xl font-semibold tracking-tight sm:text-[22px]">Week of {{ days[0].date }}</h1>
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

            <EmptyState
                :icon="PhClockCountdown"
                title="Step tracking starts with rollcalls"
                class="border-t border-border py-6"
            >
                Rollcall posting and signing, section practice, attendance and report sending will track across these
                days once rollcalls run in the portal.
            </EmptyState>
        </Card>

        <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,0.62fr)_minmax(0,1fr)]">
            <Card role="region" class="min-w-0 gap-0 py-0" aria-labelledby="inbox-title">
                <div class="flex items-center gap-2 px-4 pt-3.5">
                    <h2 id="inbox-title" class="text-[15px] font-semibold">Inbox</h2>
                    <span class="truncate text-xs text-muted-foreground">Needs you before the report goes up</span>
                </div>
                <EmptyState :icon="PhTray" title="Nothing needs you yet" class="py-12">
                    Overdue rollcalls, unmarked attendance and report drafts waiting on your sign-off will collect here.
                </EmptyState>
            </Card>

            <Card role="region" class="min-w-0 gap-0 py-0" aria-labelledby="progress-title">
                <div class="px-4 pt-3.5">
                    <h2 id="progress-title" class="text-[15px] font-semibold">Section progress</h2>
                </div>
                <div class="mt-2 overflow-x-auto border-t border-border">
                    <Table class="text-[13px]">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-auto min-w-[8.5rem] py-2 text-xs font-medium text-muted-foreground">Unit</TableHead>
                                <TableHead v-for="step in STEPS" :key="step" class="h-auto py-2 text-xs font-medium text-muted-foreground">
                                    {{ step }}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                    </Table>
                </div>
                <!-- Outside the scrolling table, so it stays centred on a phone. -->
                <EmptyState :icon="PhUsersThree" title="No sections reporting yet" class="py-10">
                    Each section's steps for the week show here once troopers are mapped to sections and rollcalls go out.
                </EmptyState>
            </Card>
        </div>

        <section aria-labelledby="trends-title">
            <h2 id="trends-title" class="mb-2 text-[15px] font-semibold">Last 30 days</h2>
            <Card class="gap-0 py-0">
                <EmptyState :icon="PhChartLine" title="Trends need a few weeks of attendance" class="py-10">
                    Rollcall sign rates and section practice attendance chart here once attendance has been recorded.
                </EmptyState>
            </Card>
        </section>
    </div>
</template>
