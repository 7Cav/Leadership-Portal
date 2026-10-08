<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhArrowsClockwise,
    PhCaretLeft,
    PhCaretRight,
    PhCaretUpDown,
    PhCheck,
    PhClipboardText,
    PhClock,
    PhClockCounterClockwise,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import EmptyState from '@/components/EmptyState.vue';
import SectionPicker from '@/components/rollcall/SectionPicker.vue';
import StepReview from '@/components/rollcall/StepReview.vue';
import TrooperCard from '@/components/rollcall/TrooperCard.vue';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { countsOf, practiceGroups, signatureGroups, stepsOf } from '@/lib/rollcall';
import { cn } from '@/lib/utils';

// The weekly rollcall of one section (Stories 3.7–3.10), binding design mockups/v2/rollcall-r3.html
// "Guided week": three steps — signatures, Section Practice, review and post — over the section's
// troopers. With no section chosen (`section` null) it is the section picker.
const props = defineProps({
    // `{ id, designation, name, platoon }`, or null for the picker.
    section: { type: Object, default: null },
    // Every section in the viewer's scope: `[{ id, designation, name, platoon }]`.
    sections: { type: Array, required: true },
    // `{ start, date, closes, isCurrent, previous, next, current }` (AD-11).
    week: { type: Object, required: true },
    // `[{ id, cavId, rank, name, rollcall, status, source, priorStatus }]`.
    troopers: { type: Array, default: () => [] },
    priorWeekMarked: { type: Boolean, default: false },
    // `[{ trooperId, missed, windowWeeks }]`, from the shared KPI service (AD-21).
    atRisk: { type: Array, default: () => [] },
    // `{ source, syncedAt }` (AD-20).
    roster: { type: Object, default: null },
    practice: { type: Object, default: null },
});

const page = usePage();

// The screen's working copy of the marks.
const rows = ref([]);
watch(
    () => props.troopers,
    (troopers) => (rows.value = troopers.map((trooper) => ({ ...trooper }))),
    { immediate: true },
);

const counts = computed(() => countsOf(rows.value));
const steps = computed(() => stepsOf(rows.value, null));

// The step is part of the URL (`?step=2`), so a step can be linked to and Back walks the steps.
const query = computed(() => new URL(page.url, 'http://portal.local').searchParams);
const step = computed(() => {
    const requested = Number(query.value.get('step'));

    return [1, 2, 3].includes(requested) ? requested : 1;
});
const stepHeading = ref(null);

function go(n) {
    const params = new URLSearchParams(query.value);
    params.set('step', String(n));
    router.push({ url: `${new URL(page.url, 'http://portal.local').pathname}?${params}`, preserveState: true, preserveScroll: true });
    nextTick(() => stepHeading.value?.focus());
}

function weekHref(date) {
    const params = date && date !== props.week.current ? `?week=${date}` : '';

    return `/rollcall/${props.section.id}${params}`;
}

const weekQuery = computed(() => (props.week.isCurrent ? null : props.week.date));
const weekLabel = computed(() => forumDate(props.week.start));
const closesLabel = computed(() => {
    const at = new Date(props.week.closes);

    return `${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][at.getUTCDay()]} ${forumDateTime(at)}Z`;
});
const syncedLabel = computed(() => (props.roster?.syncedAt ? `${forumDateTime(props.roster.syncedAt)}Z` : null));

const pickerOpen = ref(false);
const practiceFilter = ref('all');

const signatureView = computed(() => signatureGroups(rows.value));
const practiceView = computed(() => practiceGroups(rows.value, practiceFilter.value === 'open'));
</script>

<template>
    <Head :title="section ? `Rollcall ${section.designation} · 7Cav Leadership Portal` : 'Rollcall · 7Cav Leadership Portal'" />

    <!-- No section chosen: the picker. -->
    <div v-if="!section" class="mx-auto max-w-xl space-y-4">
        <div>
            <p class="text-xs text-muted-foreground">Week of {{ forumDate(week.start) }}</p>
            <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]">Choose a section</h1>
        </div>
        <SectionPicker v-if="sections.length" :sections="sections" :week="weekQuery" />
        <Card v-else class="py-0">
            <EmptyState :icon="PhUsersThree" title="No sections in your scope" class="py-12">
                Rollcalls are kept per section. Once you lead a section, or hold a grant over one, it is listed here.
            </EmptyState>
        </Card>
    </div>

    <div v-else class="mx-auto max-w-[1240px]">
        <!-- Page head -->
        <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">{{ section.designation }} weekly cycle</p>
                <h1 class="text-xl font-semibold tracking-tight sm:text-[22px]">Rollcall, week of {{ weekLabel }}</h1>
            </div>
            <div class="flex items-center gap-1 text-muted-foreground">
                <Button variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekHref(week.previous)" aria-label="Previous week" preserve-scroll><PhCaretLeft /></Link>
                </Button>
                <Button v-if="!week.isCurrent" variant="ghost" size="sm" as-child>
                    <Link :href="weekHref(week.current)">This week</Link>
                </Button>
                <Button v-else variant="ghost" size="sm" disabled>This week</Button>
                <Button v-if="week.next" variant="ghost" size="icon-sm" as-child>
                    <Link :href="weekHref(week.next)" aria-label="Next week" preserve-scroll><PhCaretRight /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next week"><PhCaretRight /></Button>
                <Button v-if="sections.length > 1" variant="outline" size="sm" class="ml-1" @click="pickerOpen = true">
                    {{ section.designation }}<PhCaretUpDown class="text-muted-foreground" />
                    <span class="sr-only">Switch section</span>
                </Button>
            </div>
            <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground sm:ml-auto sm:w-auto">
                <span class="inline-flex items-center gap-1.5" data-testid="roster-provenance">
                    <PhArrowsClockwise class="size-4" aria-hidden="true" />
                    <template v-if="syncedLabel">Roster from {{ roster.source }}, synced {{ syncedLabel }}</template>
                    <template v-else>Roster from {{ roster?.source ?? 'milpac' }}, not synced yet</template>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <PhClock class="size-4" aria-hidden="true" />Signatures close {{ closesLabel }}
                </span>
            </div>
        </div>

        <div
            v-if="!week.isCurrent"
            role="status"
            class="mt-3 flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/7 px-3.5 py-2.5 text-[13px]"
            data-testid="past-week-notice"
        >
            <PhClockCounterClockwise class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <p>You are looking at a past week, {{ weekLabel }}.</p>
        </div>

        <!-- Stepper -->
        <div
            class="sticky top-12 z-20 -mx-4 mt-3 border-b border-border bg-background/95 px-4 pt-3 pb-2 backdrop-blur sm:-mx-6 sm:px-6"
        >
            <nav aria-label="Rollcall steps">
                <ol class="grid grid-cols-3 gap-1.5">
                    <li v-for="s in steps" :key="s.n">
                        <button
                            type="button"
                            :aria-current="step === s.n ? 'step' : undefined"
                            :aria-label="`Step ${s.n}, ${s.title}, ${s.sub}`"
                            :class="
                                cn(
                                    'relative grid min-h-[3.75rem] w-full grid-cols-1 items-center justify-items-center gap-x-2.5 gap-y-0.5 rounded-md px-1 pt-1.5 pb-3 text-center hover:bg-accent/70 sm:min-h-14 sm:grid-cols-[auto_minmax(0,1fr)] sm:justify-items-start sm:px-2.5 sm:pt-2 sm:text-left',
                                    'after:absolute after:inset-x-2.5 after:bottom-1 after:h-[3px] after:rounded-full after:bg-border',
                                    s.state === 'done' && 'after:bg-success/70',
                                    s.state === 'warn' && 'after:bg-warning/70',
                                    step === s.n && 'after:bg-primary',
                                )
                            "
                            :data-step="s.n"
                            @click="go(s.n)"
                        >
                            <span
                                :class="
                                    cn(
                                        'grid size-7 place-items-center rounded-full border border-border bg-card text-xs font-semibold text-muted-foreground sm:row-span-2',
                                        s.state === 'done' && 'border-success/35 bg-success/8 text-success',
                                        s.state === 'warn' && 'border-warning/40 bg-warning/8 text-warning',
                                        step === s.n && 'border-transparent bg-primary text-primary-foreground',
                                    )
                                "
                                aria-hidden="true"
                            >
                                <PhCheck v-if="s.state === 'done' && step !== s.n" />
                                <template v-else>{{ s.n }}</template>
                            </span>
                            <span class="w-full truncate text-xs font-medium sm:text-[13px] sm:leading-tight sm:font-semibold">{{ s.title }}</span>
                            <span class="hidden w-full truncate text-xs leading-tight text-muted-foreground tabular-nums sm:block">{{ s.sub }}</span>
                        </button>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="mt-4 grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18.5rem]">
            <section class="min-w-0" :aria-labelledby="`step-${step}-title`">
                <!-- Empty roster -->
                <Card v-if="!rows.length" class="py-0">
                    <EmptyState :icon="PhClipboardText" title="No troopers placed in this section" class="py-14">
                        Troopers appear here once the roster sync maps them to {{ section.designation }} as their primary section.
                    </EmptyState>
                </Card>

                <!-- Step 1: signatures -->
                <template v-else-if="step === 1">
                    <div class="mb-1">
                        <h2 :id="`step-${step}-title`" ref="stepHeading" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">
                            Signatures
                        </h2>
                        <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                            Who signed the rollcall thread for this week. You can record a signature for a trooper who told you directly.
                        </p>
                    </div>
                    <div v-for="group in signatureView" :key="group.id" role="group" :aria-label="group.title">
                        <div class="flex items-baseline justify-between gap-3 px-1 pt-3.5 pb-1.5 text-[13px] font-semibold">
                            <span>{{ group.title }}</span>
                            <span class="text-xs font-normal text-muted-foreground tabular-nums">{{ group.troopers.length }}</span>
                        </div>
                        <div class="space-y-2">
                            <TrooperCard v-for="trooper in group.troopers" :key="trooper.id" :trooper="trooper" kind="signature" />
                        </div>
                    </div>
                    <div class="mt-5 flex items-center gap-2 py-3">
                        <Button class="ml-auto h-11 flex-1 sm:h-9 sm:flex-none" @click="go(2)">Next: section practice<PhCaretRight /></Button>
                    </div>
                </template>

                <!-- Step 2: section practice -->
                <template v-else-if="step === 2">
                    <div class="mb-1 flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1 basis-full sm:basis-0">
                            <h2 :id="`step-${step}-title`" ref="stepHeading" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">
                                Section practice
                            </h2>
                            <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                                Mark everyone who was expected. Troopers who said they could not attend are listed first.
                            </p>
                        </div>
                        <div class="inline-flex gap-0.5 rounded-md bg-muted p-0.5" role="group" aria-label="Show">
                            <button
                                v-for="option in [
                                    { id: 'all', label: 'All', count: rows.length },
                                    { id: 'open', label: 'Needs a look', count: counts.unmarked + counts.missed },
                                ]"
                                :key="option.id"
                                type="button"
                                :aria-pressed="practiceFilter === option.id"
                                class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-medium text-muted-foreground aria-pressed:bg-card aria-pressed:text-foreground aria-pressed:shadow-xs"
                                @click="practiceFilter = option.id"
                            >
                                {{ option.label }} <span class="text-[11px] text-muted-foreground tabular-nums">{{ option.count }}</span>
                            </button>
                        </div>
                    </div>

                    <div
                        role="status"
                        class="mt-3 flex items-start gap-2.5 rounded-lg border border-border px-3.5 py-2.5 text-[13px]"
                        data-testid="prefill-banner"
                    >
                        <PhClockCounterClockwise class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <p v-if="priorWeekMarked" class="flex-1">
                            Pre-filled from last week: each trooper still to mark shows last week's practice mark as a suggestion.
                        </p>
                        <p v-else class="flex-1 text-muted-foreground">Nothing pre-filled: no practice marks were recorded last week.</p>
                    </div>

                    <template v-if="practiceView.length">
                        <div v-for="group in practiceView" :key="group.id" role="group" :aria-label="group.title">
                            <div class="flex items-baseline justify-between gap-3 px-1 pt-3.5 pb-1.5 text-[13px] font-semibold">
                                <span>{{ group.title }}</span>
                                <span class="text-xs font-normal text-muted-foreground tabular-nums">{{ group.hint ?? group.troopers.length }}</span>
                            </div>
                            <div class="space-y-2">
                                <TrooperCard v-for="trooper in group.troopers" :key="trooper.id" :trooper="trooper" kind="practice" />
                            </div>
                        </div>
                    </template>
                    <EmptyState v-else :icon="PhCheck" title="Nothing needs a look" class="py-12">Everyone is marked and nobody missed.</EmptyState>

                    <div class="mt-5 flex items-center gap-2 py-3">
                        <Button variant="outline" class="h-11 sm:h-9" @click="go(1)"><PhCaretLeft />Back</Button>
                        <Button class="ml-auto h-11 flex-1 sm:h-9 sm:flex-none" @click="go(3)">Next: review and post<PhCaretRight /></Button>
                    </div>
                </template>

                <!-- Step 3: review and post -->
                <template v-else>
                    <div class="mb-1">
                        <h2 :id="`step-${step}-title`" ref="stepHeading" tabindex="-1" class="text-[17px] font-semibold tracking-tight outline-none">
                            Review and post
                        </h2>
                        <p class="mt-0.5 max-w-[62ch] text-[13px] text-muted-foreground">
                            Check the totals, copy the forum post into the rollcall thread on 7cav.us, then mark it posted.
                        </p>
                    </div>
                    <StepReview :troopers="rows" :at-risk="atRisk" @go="go" />
                </template>
            </section>

            <!-- Aside: the step's tallies -->
            <aside class="sticky top-[8.5rem] hidden space-y-4 lg:block" aria-label="Step details">
                <Card v-if="step === 1" class="gap-0 p-4 text-[13px]">
                    <p class="mb-1 font-semibold">This week's thread</p>
                    <p class="text-xs text-muted-foreground">Signatures close {{ closesLabel }}.</p>
                    <dl class="mt-3 divide-y divide-border">
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Will attend</dt><dd class="font-medium tabular-nums">{{ counts.willAttend }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Cannot attend</dt><dd class="font-medium tabular-nums">{{ counts.cannotAttend }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Unsigned</dt><dd :class="cn('font-medium tabular-nums', counts.unsigned && 'text-warning')">{{ counts.unsigned }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">On LOA</dt><dd class="font-medium text-muted-foreground tabular-nums">{{ counts.loa }}</dd></div>
                    </dl>
                </Card>
                <Card v-else-if="step === 2" class="gap-0 p-4 text-[13px]">
                    <p class="mb-1 font-semibold">Section practice</p>
                    <p class="text-xs text-muted-foreground">Week of {{ weekLabel }}.</p>
                    <dl class="mt-3 divide-y divide-border">
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Present</dt><dd class="font-medium tabular-nums">{{ counts.present }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Missed</dt><dd :class="cn('font-medium tabular-nums', counts.missed && 'text-destructive')">{{ counts.missed }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Makeup</dt><dd class="font-medium tabular-nums">{{ counts.makeup }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Attached</dt><dd class="font-medium tabular-nums">{{ counts.attached }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">Not marked</dt><dd :class="cn('font-medium tabular-nums', counts.unmarked && 'text-warning')">{{ counts.unmarked }}</dd></div>
                        <div class="flex justify-between py-1.5"><dt class="text-muted-foreground">On LOA</dt><dd class="font-medium text-muted-foreground tabular-nums">{{ counts.loa }}</dd></div>
                    </dl>
                </Card>
            </aside>
        </div>

        <Dialog v-model:open="pickerOpen">
            <DialogContent class="gap-3 p-4 sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Switch section</DialogTitle>
                    <DialogDescription>Open another section's rollcall for the same week.</DialogDescription>
                </DialogHeader>
                <SectionPicker :sections="sections" :current-id="section.id" :week="weekQuery" @picked="pickerOpen = false" />
            </DialogContent>
        </Dialog>
    </div>
</template>
