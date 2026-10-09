<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { forumDate, forumDateTime } from '@/lib/forumDate';

// One trooper's milpac (Stories 6.3 to 6.6): the ID block from the cached Cav roster, the courses and
// qualifications panel, the earned-awards history and the service-record timeline. Everything is shaped on the
// server (`Milpac\TrooperMilpac`) and drawn here; the page decides nothing about who may look, the controller
// has already gated it.
const props = defineProps({
    trooper: { type: Object, required: true },
    courses: { type: Object, required: true },
    awards: { type: Object, required: true },
    timeline: { type: Object, required: true },
    sync: { type: Object, required: true },
});

const STATUS = {
    combat: { label: 'Active', variant: 'success' },
    reserve: { label: 'Reserve', variant: 'info' },
    eloa: { label: 'ELOA', variant: 'warning' },
    inactive: { label: 'Departed', variant: 'danger' },
};

const status = computed(() => STATUS[props.trooper.status] ?? { label: props.trooper.status, variant: 'outline' });
// The avatar's letters, as the mockup draws them: the surname's first letter and the given initial ("Milkovic.D"
// is MD). A name with no dot takes its first two letters.
const initials = computed(() => {
    const name = props.trooper.username ?? '';
    const [surname, given] = name.split('.');
    const letters = given ? `${surname[0] ?? ''}${given[0]}` : name.slice(0, 2);

    return letters.replace(/[^A-Za-z]/g, '').toUpperCase() || '?';
});
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
// One colour per timeline event type, as the mockup draws them: courses blue, promotions amber, awards green.
const EVENT_DOT = { course: 'bg-info', promotion: 'bg-warning', award: 'bg-success' };

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

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight" data-testid="trooper-name">{{ trooper.displayName }}</h1>
                <p class="mt-1 text-[13px] text-muted-foreground" data-testid="trooper-subtitle">
                    <template v-if="trooper.billet">{{ trooper.billet }}</template>
                    <template v-else>No billet on record</template>
                    <template v-if="sectionLabel"> · {{ sectionLabel }}</template>
                    · Profile #{{ trooper.cavId }}
                    ·
                    <a
                        :href="trooper.milpacUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-primary hover:underline"
                        data-testid="milpac-link"
                    >
                        view on 7cav.us <PhArrowSquareOut class="size-3" aria-hidden="true" /><span class="sr-only">(opens in a new tab)</span>
                    </a>
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

        <p
            v-if="staleNotice"
            data-testid="stale-notice"
            role="status"
            class="flex items-start gap-2 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2.5 text-[13px]"
        >
            <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <span>{{ staleNotice }}</span>
        </p>

        <div class="grid items-start gap-5 lg:grid-cols-[20rem_1fr]">
            <div class="flex flex-col gap-5">
                <Card class="gap-0 overflow-hidden py-0" data-testid="id-block">
                    <div class="flex flex-col items-center gap-1 px-5 pt-6 pb-5 text-center">
                        <div
                            class="mb-2 flex size-16 items-center justify-center rounded-full border-2 border-primary text-lg font-semibold text-primary"
                            aria-hidden="true"
                        >
                            {{ initials }}
                        </div>
                        <h2 class="text-base font-semibold">{{ trooper.displayName }}</h2>
                        <p class="text-[13px] text-primary" data-testid="billet">{{ trooper.billet ?? 'No billet on record' }}</p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="trooper.tis">Joined <span data-testid="joined">{{ forumDate(trooper.tis) }}</span> · </template>
                            MOS <span data-testid="mos">{{ trooper.mos ?? 'None' }}</span>
                        </p>
                        <Badge :variant="status.variant" class="mt-2" data-testid="status">{{ status.label }}</Badge>
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
                                <span v-else class="text-muted-foreground" data-testid="unmapped">Unmapped: not placed in a section yet</span>
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card class="gap-0 py-0" data-testid="courses-panel">
                    <div class="flex items-center justify-between border-b border-border px-4 py-3">
                        <h2 class="text-[15px] font-semibold">Courses &amp; qualifications</h2>
                        <Badge v-if="courses.total" variant="warning" class="rounded-full tabular-nums" data-testid="courses-count">
                            {{ courses.qualified }} / {{ courses.total }}
                        </Badge>
                    </div>

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
                </Card>
            </div>

            <div class="flex flex-col gap-5">
                <Card class="gap-0 py-0" data-testid="timeline-panel">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                        <h2 class="text-[15px] font-semibold">Service record</h2>
                        <ul v-if="timeline.legend.length" class="flex flex-wrap gap-3 text-xs text-muted-foreground" data-testid="timeline-legend">
                            <li v-for="item in timeline.legend" :key="item.type" class="flex items-center gap-1.5" :data-testid="`legend-${item.type}`">
                                <span class="size-2 rounded-full" :class="EVENT_DOT[item.type]" aria-hidden="true" />
                                {{ item.label }}
                            </li>
                        </ul>
                    </div>

                    <p v-if="!timeline.entries.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="timeline-empty">
                        No service record events on file.
                    </p>

                    <ol v-else class="flex flex-col gap-2 px-4 py-4">
                        <li
                            v-for="(entry, index) in timeline.entries"
                            :key="`${entry.type}-${entry.date}-${index}`"
                            class="flex items-start gap-3 rounded-lg border border-border bg-muted/20 px-3.5 py-2.5 text-[13px]"
                            :data-testid="`event-${entry.type}`"
                        >
                            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="EVENT_DOT[entry.type]" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-[11px] text-muted-foreground" data-testid="event-date">{{ forumDate(entry.date) }}</p>
                                <p class="font-medium" data-testid="event-title">{{ entry.title }}</p>
                                <p v-if="entry.detail" class="text-xs text-muted-foreground" data-testid="event-detail">{{ entry.detail }}</p>
                            </div>
                        </li>
                    </ol>

                    <p class="border-t border-border px-4 py-2 text-xs text-muted-foreground" data-testid="timeline-source">
                        Source: {{ sync.source }}
                        <template v-if="sync.lastSuccessAt"> · last synced {{ forumDateTime(sync.lastSuccessAt) }} UTC</template>
                        <template v-else> · never synced</template>
                    </p>
                </Card>

                <!-- Read-only history from the Cav roster (AD-5): there is deliberately no way to add, edit or recommend an award here (FR17). -->
                <Card class="gap-0 py-0" data-testid="awards-panel">
                    <div class="flex items-center justify-between border-b border-border px-4 py-3">
                        <h2 class="text-[15px] font-semibold">Awards</h2>
                        <Badge v-if="awards.items.length" variant="warning" class="rounded-full tabular-nums" data-testid="awards-count">
                            {{ awards.items.length }}
                        </Badge>
                    </div>

                    <p v-if="!awards.items.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="awards-empty">
                        No awards on record.
                    </p>

                    <ul v-else>
                        <li
                            v-for="award in awards.items"
                            :key="`${award.awardedAt}-${award.name}`"
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

                    <p class="border-t border-border px-4 py-2 text-xs text-muted-foreground" data-testid="awards-source">
                        Source: {{ sync.source }}
                        <template v-if="sync.lastSuccessAt"> · last synced {{ forumDateTime(sync.lastSuccessAt) }} UTC</template>
                        <template v-else> · never synced</template>
                    </p>
                </Card>
            </div>
        </div>
    </div>
</template>
