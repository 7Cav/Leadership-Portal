<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight, PhCheckCircle, PhClipboardText, PhInfo, PhUsersThree, PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import EmptyState from '@/components/EmptyState.vue';
import LoaBadge from '@/components/LoaBadge.vue';
import StatusSegments from '@/components/StatusSegments.vue';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { compareSeniorFirst } from '@/lib/ranks';

// The weekly rollcall of one section (Story 3.7). The server (`Rollcall\RollcallScreen`) shapes everything and the
// controller has already gated the section; this page draws it. Marking is Story 3.8: until then every control is
// read-only, so the screen shows what is there and what was carried over, and offers nothing to press.
const props = defineProps({
    section: { type: Object, default: null },
    sections: { type: Array, default: () => [] },
    week: { type: Object, default: null },
    troopers: { type: Array, default: () => [] },
    banner: { type: Object, default: null },
    sync: { type: Object, default: null },
});

const sectionLabel = computed(() => (props.section?.callsign ? `${props.section.callsign} ${props.section.name}` : props.section?.name));
const optionLabel = (section) => (section.callsign ? `${section.callsign} ${section.name}` : section.name);

const rows = computed(() => [...props.troopers].sort(compareSeniorFirst));

const RC_OPTIONS = [
    { value: 'will_attend', label: 'Yes', title: 'Signed the rollcall thread: will attend' },
    { value: 'cannot_attend', label: 'No', title: 'Signed the rollcall thread: cannot attend' },
];
const SP_OPTIONS = [
    { value: 'present', label: 'Yes', title: 'Attended Section Practice' },
    { value: 'missed', label: 'No', title: 'Missed Section Practice' },
    { value: 'makeup', label: 'MU', title: 'Made up Section Practice another day' },
    { value: 'attached', label: 'Att', title: 'Attended with another section, credited here' },
];
const TONES = {
    will_attend: 'bg-success/25',
    cannot_attend: 'bg-destructive/25',
    present: 'bg-success/25',
    missed: 'bg-destructive/25',
    makeup: 'bg-info/25',
    attached: 'bg-warning/25',
};

// The pre-fill banner, said in words so a leader knows what is a suggestion and what is saved.
const bannerText = computed(() => {
    const b = props.banner;
    if (!b) return '';

    const parts = [];
    if (b.carried > 0) {
        parts.push(`Carried "present" over from ${forumDate(b.fromWeek)} for ${b.carried} of ${b.eligible} troopers.`);
    } else if (b.saved === 0) {
        parts.push(
            b.fromWeek
                ? `Nothing was pre-filled: nobody was present on ${forumDate(b.fromWeek)} to carry over.`
                : 'Nothing was pre-filled: this section has no earlier week to carry over.',
        );
    }
    if (b.saved > 0) parts.push(`${b.saved} of ${b.eligible} already saved for this week.`);
    parts.push('Troopers on leave are shown by their LOA. Everything else is left for you to mark by hand.');

    return parts.join(' ');
});
const prefilled = computed(() => (props.banner?.carried ?? 0) > 0);

const syncLines = computed(() => {
    if (!props.sync) return [];

    return [
        ['Roster', props.sync.roster],
        ['Leave', props.sync.loa],
    ].map(([name, s]) => ({
        name,
        text: `${s.source} · ${s.lastSyncedAt ? `last synced ${forumDateTime(s.lastSyncedAt)} UTC` : 'never synced'}`,
        stale: s.stale,
    }));
});

function chooseSection(event) {
    router.get(`/rollcall/${event.target.value}`);
}

const weekHref = (start) => `/rollcall/${props.section.id}?week=${start}`;
</script>

<template>
    <Head :title="section ? `Rollcall · ${sectionLabel}` : 'Rollcall'" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6">
        <EmptyState v-if="!section" :icon="PhClipboardText" title="No sections in your scope." data-testid="no-section">
            A rollcall is kept per section. Once you hold a section, it opens here.
        </EmptyState>

        <template v-else>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold tracking-tight" data-testid="rollcall-title">Rollcall · {{ sectionLabel }}</h1>
                    <p class="mt-1 text-[13px] text-muted-foreground" data-testid="week-range">
                        Week of <span class="font-mono">{{ forumDate(week.start) }} – {{ forumDate(week.end) }}</span>
                        <Badge v-if="week.isCurrent" variant="outline" class="ml-1.5" data-testid="current-week">This week</Badge>
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-2">
                    <div v-if="sections.length > 1" class="grid gap-1">
                        <Label for="rollcall-section" class="sr-only">Section</Label>
                        <NativeSelect id="rollcall-section" data-testid="section-select" :model-value="section.id" @change="chooseSection">
                            <option v-for="item in sections" :key="item.id" :value="item.id">{{ optionLabel(item) }}</option>
                        </NativeSelect>
                    </div>

                    <nav aria-label="Week" class="flex items-center gap-1.5">
                        <Button as-child variant="outline" size="sm">
                            <Link :href="weekHref(week.previous)" data-testid="previous-week"><PhCaretLeft aria-hidden="true" />Previous week</Link>
                        </Button>
                        <Button as-child variant="outline" size="sm" :disabled="week.isCurrent">
                            <Link :href="`/rollcall/${section.id}`" data-testid="this-week">This week</Link>
                        </Button>
                        <Button v-if="week.next" as-child variant="outline" size="sm">
                            <Link :href="weekHref(week.next)" data-testid="next-week">Next week<PhCaretRight aria-hidden="true" /></Link>
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled data-testid="next-week-disabled">Next week<PhCaretRight aria-hidden="true" /></Button>
                    </nav>
                </div>
            </div>

            <p
                v-if="banner"
                data-testid="prefill-banner"
                role="status"
                class="flex items-start gap-2 rounded-lg border px-3.5 py-2.5 text-[13px]"
                :class="prefilled ? 'border-success/35 bg-success/8' : 'border-border bg-muted/30'"
            >
                <component :is="prefilled ? PhCheckCircle : PhInfo" class="mt-0.5 size-4 shrink-0" :class="prefilled ? 'text-success' : 'text-muted-foreground'" aria-hidden="true" />
                <span class="flex-1">{{ bannerText }}</span>
                <span class="flex shrink-0 items-center gap-3 text-xs text-muted-foreground" data-testid="source-legend" aria-label="Source legend">
                    <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-success" aria-hidden="true" />auto</span>
                    <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-warning" aria-hidden="true" />manual</span>
                    <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-info" aria-hidden="true" />makeup</span>
                </span>
            </p>

            <p v-for="line in syncLines" :key="line.name" class="-mt-3 flex items-center gap-1.5 text-xs text-muted-foreground" data-testid="sync-line">
                <PhWarning v-if="line.stale" class="size-3.5 text-warning" aria-hidden="true" />
                <span>{{ line.name }}: {{ line.text }}</span>
                <span v-if="line.stale" class="text-warning" data-testid="stale">(the latest sync did not succeed, so this is the last good copy)</span>
            </p>

            <Card class="gap-0 overflow-hidden py-0">
                <EmptyState v-if="!rows.length" :icon="PhUsersThree" title="No troopers are placed in this section." class="py-10" data-testid="roster-empty">
                    When the roster sync places troopers here, they appear with the week's attendance.
                </EmptyState>

                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]" data-testid="roster">
                        <thead>
                            <tr class="border-b border-border bg-muted/30 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                <th scope="col" class="w-10 px-3 py-2.5 text-left">#</th>
                                <th scope="col" class="px-3 py-2.5 text-left">Trooper</th>
                                <th scope="col" class="px-3 py-2.5 text-left">Signed RC</th>
                                <th scope="col" class="px-3 py-2.5 text-left">Attended SP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in rows" :key="row.id" class="border-b border-border last:border-b-0" data-testid="trooper-row">
                                <td class="px-3 py-2.5 font-mono text-xs text-muted-foreground">{{ index + 1 }}</td>
                                <th scope="row" class="px-3 py-2.5 text-left font-normal">
                                    <span class="font-medium" data-testid="trooper-name">{{ row.displayName }}</span>
                                    <LoaBadge v-if="row.loa.state === 'needs_review'" :cover="row.loa" :sync="sync.loa" class="ml-2" />
                                    <span class="block text-xs text-muted-foreground">{{ row.billet ?? 'No billet on record' }}</span>
                                </th>
                                <td class="px-3 py-2.5">
                                    <StatusSegments :options="RC_OPTIONS" :model-value="row.rollcall === 'unsigned' ? null : row.rollcall" :tones="TONES" :label="`Signed rollcall, ${row.displayName}`" />
                                    <span v-if="row.rollcall === 'unsigned'" class="ml-1.5 text-xs text-muted-foreground" data-testid="unsigned">not signed</span>
                                </td>
                                <td class="px-3 py-2.5" data-testid="sp-cell" :data-origin="row.practice.origin ?? 'none'">
                                    <LoaBadge v-if="row.practice.origin === 'leave'" :cover="row.loa" :sync="sync.loa" />
                                    <template v-else>
                                        <StatusSegments
                                            :options="SP_OPTIONS"
                                            :model-value="row.practice.value"
                                            :tones="TONES"
                                            :dashed="row.practice.origin === 'carried'"
                                            :label="`Attended Section Practice, ${row.displayName}`"
                                        />
                                        <span v-if="row.practice.origin === 'carried'" class="ml-1.5 text-xs text-muted-foreground" data-testid="carried">from last week</span>
                                        <span v-else-if="!row.practice.value" class="ml-1.5 text-xs text-muted-foreground" data-testid="unmarked">not marked</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </template>
    </div>
</template>
