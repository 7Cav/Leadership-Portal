<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PhCaretLeft, PhCaretRight, PhCheckCircle, PhClipboardText, PhCopy, PhInfo, PhLock, PhUsersThree, PhWarning } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import EmptyState from '@/components/EmptyState.vue';
import LoaBadge from '@/components/LoaBadge.vue';
import SourceDot from '@/components/SourceDot.vue';
import SourceLegend from '@/components/SourceLegend.vue';
import StatusSegments from '@/components/StatusSegments.vue';
import { Textarea } from '@/components/ui/textarea';
import { render, rollcallContext } from '@/lib/bbcode';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { compareSeniorFirst } from '@/lib/ranks';

// The weekly rollcall of one section (Stories 3.7 to 3.10). The server (`Rollcall\RollcallScreen`) shapes everything and the
// controller has already gated the section; this page draws it and lets a leader mark the week. Nothing is saved until
// "Save rollcall": the toggles change a draft, and a value carried over from last week is only a suggestion until then.
// A trooper on leave has no practice toggle (their status is derived, never entered), and while viewing as someone else
// every control is off, since a preview is read-only. So is every control once the section's weekly report for the week
// has been sent (`locked`): the report is frozen, and so is the rollcall it was built from. The server enforces all of
// it whatever this page shows (AD-6); the page only says so before a leader tries.
// The BBCode beside the table is rendered here from the company's active template and the draft, so it follows every
// toggle with no round trip. It is only ever copied: the portal makes no call to the forum (AD-20).
const props = defineProps({
    section: { type: Object, default: null },
    sections: { type: Array, default: () => [] },
    week: { type: Object, default: null },
    template: { type: Object, default: null },
    locked: { type: Boolean, default: false },
    troopers: { type: Array, default: () => [] },
    banner: { type: Object, default: null },
    sync: { type: Object, default: null },
});

const page = usePage();
const previewing = computed(() => Boolean(page.props.viewAs));
// Nothing on the page can change the week: a preview, or a week whose report has been sent.
const readOnly = computed(() => previewing.value || props.locked);

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

// ---- The draft: what the leader has chosen so far, row by row.
const onLeave = (row) => row.practice.origin === 'leave';
const startingSp = (row) => (onLeave(row) ? null : row.practice.value);
const startingRc = (row) => (row.rollcall === 'unsigned' ? null : row.rollcall);

const draft = reactive({});
function resetDraft() {
    for (const key of Object.keys(draft)) delete draft[key];
    for (const row of props.troopers) draft[row.id] = { sp: startingSp(row), rc: startingRc(row) };
}
// A new week, section or save arrives as new props, so the draft starts again from them.
watch(() => props.troopers, resetDraft, { immediate: true });

// A saved mark cannot be cleared (a correction is another status), but a choice nobody has saved can be taken back.
function chooseSp(row, value) {
    const d = draft[row.id];

    if (d.sp === value) {
        if (row.practice.origin !== 'saved') d.sp = null;

        return;
    }

    d.sp = value;
}

function chooseRc(row, value) {
    const d = draft[row.id];
    d.rc = d.rc === value ? null : value;
}

// "Mark all present" is the same as choosing it row by row: it sets the draft, never a second write path.
function markAllPresent() {
    for (const row of props.troopers) {
        if (!onLeave(row)) draft[row.id].sp = 'present';
    }
}

// What is sent: a practice status the leader changed, or a carried suggestion they left in place (submitting accepts it),
// and a signature they changed. A saved mark they did not touch is not sent, so re-saving never rewrites someone else's.
const marks = computed(() =>
    props.troopers.flatMap((row) => {
        const d = draft[row.id];
        if (!d) return [];

        const mark = { trooper_id: row.id };
        const changed = d.sp !== startingSp(row);
        const accepted = row.practice.origin === 'carried' && d.sp === row.practice.value;
        if (!onLeave(row) && d.sp !== null && (changed || accepted)) mark.status = d.sp;
        if (d.rc !== startingRc(row)) mark.rollcall = d.rc ?? 'unsigned';

        return mark.status || mark.rollcall ? [mark] : [];
    }),
);

const originOf = (row) => {
    if (onLeave(row)) return 'leave';

    return draft[row.id]?.sp === startingSp(row) ? row.practice.origin : 'edited';
};

// ---- The forum BBCode (Story 3.10).
// What the leader sees is the template the company has active, filled from the draft. A section with no template, or a
// template that cannot be rendered, says so instead of showing broken BBCode.
const NO_TEMPLATE = {
    no_active_template: 'This section\'s company has no rollcall template yet. Ask an Admin to write one in Settings, Templates.',
    no_owning_company: 'This section is not under a company, so it has no template to render from.',
};

const bbcode = computed(() => {
    if (!props.template || props.template.state !== 'resolved') {
        return { ok: false, message: NO_TEMPLATE[props.template?.state] ?? NO_TEMPLATE.no_active_template, missing: true };
    }

    const result = render(props.template.body, rollcallContext(props, draft, compareSeniorFirst));

    return result.ok ? { ok: true, text: result.text } : { ok: false, message: `The template could not be rendered: ${result.error} Ask an Admin to fix it.` };
});

// A copy is the browser's clipboard and nothing else: no request is made.
async function copyBbcode() {
    try {
        await navigator.clipboard.writeText(bbcode.value.text);
        toast.success('BBCode copied. Paste it into the forum thread.');
    } catch {
        toast.error('Could not copy. Select the BBCode and copy it yourself.');
    }
}

// ---- Saving.
const processing = ref(false);
const errors = ref({});
const errorText = computed(() => Object.values(errors.value)[0] ?? null);

function save() {
    processing.value = true;
    errors.value = {};

    router.post(
        `/rollcall/${props.section.id}`,
        { week: props.week.start, marks: marks.value },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Rollcall saved'),
            onError: (e) => {
                errors.value = e;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

// ---- The pre-fill banner, said in words so a leader knows what is a suggestion and what is saved.
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

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-5 px-4 py-6 sm:px-6">
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
                <SourceLegend />
            </p>

            <p v-if="previewing" data-testid="preview-notice" role="status" class="flex items-start gap-2 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2.5 text-[13px]">
                <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                <span>You are viewing as someone else. A preview is read-only, so nothing here can be saved. Exit view as to mark the rollcall.</span>
            </p>

            <p v-if="locked" data-testid="locked-notice" role="status" class="flex items-start gap-2 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2.5 text-[13px]">
                <PhLock class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                <span>The report for this week has been sent, so its rollcall is locked. The marks below are what the report was built from.</span>
            </p>

            <p v-for="line in syncLines" :key="line.name" class="-mt-3 flex items-center gap-1.5 text-xs text-muted-foreground" data-testid="sync-line">
                <PhWarning v-if="line.stale" class="size-3.5 text-warning" aria-hidden="true" />
                <span>{{ line.name }}: {{ line.text }}</span>
                <span v-if="line.stale" class="text-warning" data-testid="stale">(the latest sync did not succeed, so this is the last good copy)</span>
            </p>

            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_26rem]">
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
                                <td class="px-3 py-2.5" data-testid="rc-cell">
                                    <StatusSegments
                                        :options="RC_OPTIONS"
                                        :model-value="draft[row.id]?.rc ?? null"
                                        :tones="TONES"
                                        :readonly="readOnly"
                                        :label="`Signed rollcall, ${row.displayName}`"
                                        @update:model-value="(value) => chooseRc(row, value)"
                                    />
                                    <span v-if="!draft[row.id]?.rc" class="ml-1.5 text-xs text-muted-foreground" data-testid="unsigned">not signed</span>
                                </td>
                                <td class="px-3 py-2.5" data-testid="sp-cell" :data-origin="originOf(row) ?? 'none'">
                                    <LoaBadge v-if="onLeave(row)" :cover="row.loa" :sync="sync.loa" />
                                    <template v-else>
                                        <!-- The dot says where a stored mark came from. A carried or unsaved value is not stored, so it has none. -->
                                        <SourceDot v-if="originOf(row) === 'saved'" :source="row.practice.source" :status="row.practice.value" class="mr-2" />
                                        <StatusSegments
                                            :options="SP_OPTIONS"
                                            :model-value="draft[row.id]?.sp ?? null"
                                            :tones="TONES"
                                            :dashed="originOf(row) === 'carried'"
                                            :readonly="readOnly"
                                            :label="`Attended Section Practice, ${row.displayName}`"
                                            @update:model-value="(value) => chooseSp(row, value)"
                                        />
                                        <span v-if="originOf(row) === 'carried'" class="ml-1.5 text-xs text-muted-foreground" data-testid="carried">from last week</span>
                                        <span v-else-if="originOf(row) === 'edited'" class="ml-1.5 text-xs text-muted-foreground" data-testid="edited">unsaved</span>
                                        <span v-else-if="!draft[row.id]?.sp" class="ml-1.5 text-xs text-muted-foreground" data-testid="unmarked">not marked</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/20 px-4 py-3" data-testid="actions">
                    <p v-if="errorText" role="alert" class="text-xs text-destructive" data-testid="save-error">{{ errorText }}</p>
                    <p v-else-if="locked" class="text-xs text-muted-foreground" data-testid="save-summary">Locked: the report for this week has been sent.</p>
                    <p v-else class="text-xs text-muted-foreground" data-testid="save-summary">
                        {{ marks.length ? `${marks.length} to save` : 'Nothing to save.' }}
                        <template v-if="prefilled"> Saving accepts what was carried over.</template>
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" :disabled="readOnly || !rows.length" data-testid="mark-all-present" @click="markAllPresent">Mark all present</Button>
                        <Button variant="outline" size="sm" :disabled="readOnly || processing" data-testid="reset" @click="resetDraft">Reset</Button>
                        <Button size="sm" :disabled="readOnly || processing" data-testid="save" @click="save">Save rollcall</Button>
                    </div>
                </div>
            </Card>

            <Card class="gap-0 py-0" data-testid="bbcode-panel">
                <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <h2 class="text-[13px] font-semibold tracking-wide uppercase">Forum BBCode</h2>
                    <Badge v-if="template?.version" variant="outline" data-testid="template-version">Template v{{ template.version }}</Badge>
                </div>

                <div class="space-y-3 p-4">
                    <p v-if="!bbcode.ok" class="text-[13px] text-muted-foreground" :data-testid="bbcode.missing ? 'bbcode-missing' : 'bbcode-error'" role="status">{{ bbcode.message }}</p>

                    <template v-else>
                        <Textarea :model-value="bbcode.text" readonly rows="18" class="font-mono text-xs" aria-label="Rollcall BBCode, ready to copy" data-testid="bbcode-text" />
                        <Button size="sm" class="w-full" data-testid="copy-bbcode" @click="copyBbcode"><PhCopy aria-hidden="true" />Copy BBCode</Button>
                    </template>

                    <p class="text-xs text-muted-foreground" data-testid="bbcode-note">
                        Review it, copy it and post it in the weekly thread yourself. The portal never posts to the forum.
                    </p>
                </div>
            </Card>
            </div>
        </template>
    </div>
</template>
