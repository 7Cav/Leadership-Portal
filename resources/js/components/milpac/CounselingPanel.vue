<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { PhArrowCounterClockwise, PhArrowRight, PhChatText, PhDownloadSimple, PhEye, PhLock, PhPlus, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import MilpacPanel from '@/components/milpac/MilpacPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useNow } from '@/composables/useNow';
import { COUNSELING_TYPES, forecastFlags, letterDate, recordBadge, todayUtc } from '@/lib/counseling';
import { downloadCounselingDocument } from '@/lib/counselingPdf';
import { onHttpException } from '@/lib/httpError';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from 'vue-sonner';

// The trooper's Developmental Counseling Statements and Letters of Reprimand (#381, #565, milpac-desktop mockup). The
// server sends only the records this viewer may see, where each stands in the wiki procedure, how they count toward the
// trooper's standing (each record's flags, the no favorable action banner, and for a new record what it would earn),
// and what the viewer may do on each (`can`). A record is written on its own page (Counseling/Record): "New DCS or
// LOR" starts a draft there, and each row opens it. Hiding a control is cosmetic: every route checks again.
const props = defineProps({
    trooperId: { type: Number, required: true },
    // `Milpac\CounselingHistory` plus `departed`, `sees`, `canFile` and `kinds`, from TrooperDetailController.
    counseling: { type: Object, required: true },
});

const records = computed(() => props.counseling.records ?? []);
const kinds = computed(() => props.counseling.kinds ?? []);
const canFile = computed(() => Object.keys(COUNSELING_TYPES).filter((type) => props.counseling.canFile?.[type]));
const seesBoth = computed(() => props.counseling.sees?.dcs && props.counseling.sees?.lor);
const now = useNow();
const DAY = 24 * 60 * 60 * 1000;

// ---- Starting a draft ----

const starting = ref(false);
const start = useForm({ type: 'dcs', kind: '' });
const hint = computed(() => forecastFlags(props.counseling.forecast, start.type, start.kind, todayUtc()));

function openStart() {
    start.clearErrors();
    start.type = canFile.value[0];
    start.kind = '';
    starting.value = true;
}

function submitStart() {
    if (!start.kind) {
        start.setError('kind', 'Choose what the record is for.');
        return;
    }
    // The server opens the new draft's page.
    start.post(`/troopers/${props.trooperId}/counseling`, {
        onHttpException: onHttpException('The draft was not started', (message) => toast.error(message)),
    });
}

// ---- The standing over the last 90 days ----

// The shared records that count: each DCS active then inactive along its 90 days, each LOR a mark on the day it was shared.
const today = computed(() => Date.parse(`${todayUtc(new Date(now.value))}T00:00:00Z`));
const windowStart = computed(() => today.value - 90 * DAY);
const place = (at) => Math.min(100, Math.max(0, ((at - windowStart.value) / (today.value + DAY - windowStart.value)) * 100));
const standing = computed(() =>
    records.value
        .filter((r) => r.sharedAt && !r.overturned && r.dcsPhase !== 'expired' && r.state !== 'draft' && r.state !== 'invited')
        .map((r) => {
            const shared = Date.parse(`${r.sharedAt.slice(0, 10)}T00:00:00Z`);
            const inactive = r.inactiveOn ? Date.parse(`${r.inactiveOn}T00:00:00Z`) : null;
            const expires = r.expiresOn ? Date.parse(`${r.expiresOn}T00:00:00Z`) : null;

            return {
                id: r.id,
                mark: place(shared),
                active: inactive ? { from: place(shared), to: place(inactive) } : null,
                inactive: inactive && expires ? { from: place(inactive), to: place(expires) } : null,
            };
        })
        .filter((r) => r.mark > 0 || r.inactive?.to > 0),
);
const windowLabel = computed(() => letterDate(new Date(windowStart.value).toISOString()).replace(/ \d{4}$/, ''));

// ---- One row ----

const dayBefore = (date) => letterDate(new Date(Date.parse(`${date}T00:00:00Z`) - DAY).toISOString());
const STATE_WORDS = { shared: 'waiting for the trooper', acknowledged: 'acknowledged', no_response: 'no response', appealed: 'appealed', decided: 'appeal decided' };

function meta(record) {
    if (['draft', 'invited'].includes(record.state)) {
        const saved = timeAgo(record.savedAt, now.value);

        return [
            `Started ${letterDate(record.startedAt)} by ${record.issuer}`,
            record.meeting?.invitedAt ? 'meeting invite sent' : null,
            saved ? `saved ${saved}` : null,
            'only you and the chain see it',
        ].filter(Boolean);
    }

    const parts = [`${letterDate(record.date) || 'No date'} by ${record.issuer}`];
    if (record.source === 'import') parts.push('from the counseling import');
    else if (record.source === 'upload') parts.push('filed as a PDF');
    if (STATE_WORDS[record.state] && record.source === 'portal') parts.push(STATE_WORDS[record.state]);
    if (record.dcsPhase === 'active' && record.inactiveOn) parts.push(`the trooper sees it until ${dayBefore(record.inactiveOn)}`);
    if (record.dcsPhase === 'inactive' && record.expiresOn) parts.push(`hidden from the trooper · counts until ${dayBefore(record.expiresOn)}`);
    if (record.dcsPhase === 'expired') parts.push('expired, no longer counts');

    return parts;
}

const isDraft = (record) => ['draft', 'invited'].includes(record.state);
// Every record the viewer sees opens: a record kept with its source PDF too, where its chain fills in its fields.
const opens = (record) => !!record.can?.open;

// A seeded demo record's document has no file behind it: say so instead of starting a download that would fail.
const demoDocumentOpen = ref(false);
const downloading = ref(null);

async function download(record) {
    if (record.document?.demo) {
        demoDocumentOpen.value = true;
        return;
    }
    downloading.value = record.id;
    try {
        await downloadCounselingDocument(record.id);
    } catch (error) {
        toast.error('The PDF could not be downloaded', { description: error?.message });
    } finally {
        downloading.value = null;
    }
}

const emptyText = computed(() => (seesBoth.value ? 'No counseling records on file.' : 'No Letters of Reprimand on file.'));
const typeShort = (type) => COUNSELING_TYPES[type]?.short ?? type;
const typeLabel = (type) => COUNSELING_TYPES[type]?.label ?? type;
</script>

<template>
    <MilpacPanel title="Counseling" data-testid="counseling-panel">
        <template v-if="records.length" #count>
            <Badge variant="secondary" class="rounded-full tabular-nums" :aria-label="`${records.length} ${records.length === 1 ? 'record' : 'records'}`" data-testid="counseling-count">
                {{ records.length }}
            </Badge>
        </template>

        <div v-if="counseling.departed || counseling.warnings?.length || canFile.length || standing.length" class="flex flex-col gap-3 border-b border-border px-4 py-3">
            <InlineNotice v-if="counseling.departed" :icon="PhLock" role="status" data-testid="counseling-departed">
                This trooper is no longer serving, so their counseling records are read-only. The records on file can still be opened and downloaded.
            </InlineNotice>

            <InlineNotice v-for="warning in counseling.warnings" :key="warning.key" tone="warning" :icon="PhWarning" role="status" :data-testid="`counseling-warning-${warning.key}`">
                {{ warning.message }}
            </InlineNotice>

            <div v-if="standing.length" data-testid="counseling-standing">
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>{{ windowLabel }}</span>
                    <span>Counts toward standing (90 days)</span>
                    <span>Today</span>
                </div>
                <div
                    class="relative mt-1.5 h-1.5 rounded-full bg-muted"
                    role="img"
                    :aria-label="`${standing.length} ${standing.length === 1 ? 'record counts' : 'records count'} toward the trooper's standing in the last 90 days`"
                >
                    <template v-for="entry in standing" :key="entry.id">
                        <span v-if="entry.inactive" class="absolute inset-y-0 bg-muted-foreground/40" :style="{ left: `${entry.inactive.from}%`, width: `${entry.inactive.to - entry.inactive.from}%` }" data-testid="standing-inactive" />
                        <span v-if="entry.active" class="absolute inset-y-0 bg-foreground" :style="{ left: `${entry.active.from}%`, width: `${entry.active.to - entry.active.from}%` }" data-testid="standing-active" />
                        <span class="absolute -inset-y-0.5 w-0.5 rounded-full bg-primary" :style="{ left: `${entry.mark}%` }" data-testid="standing-mark" />
                    </template>
                </div>
                <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-foreground" aria-hidden="true" />Active: the trooper sees it</li>
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-muted-foreground/40" aria-hidden="true" />Inactive: Platoon Leader and up</li>
                </ul>
            </div>

            <div v-if="canFile.length">
                <Button variant="outline" size="sm" data-testid="counseling-start-open" @click="openStart"><PhPlus aria-hidden="true" />New DCS or LOR</Button>
            </div>
        </div>

        <p v-if="!records.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="counseling-empty">{{ emptyText }}</p>

        <ul v-else>
            <li
                v-for="record in records"
                :key="record.id"
                class="flex flex-col gap-1.5 border-b border-border px-4 py-3 text-[13px] last:border-b-0"
                data-testid="counseling-record"
                :data-type="record.type"
                :data-state="record.state"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge variant="outline" :title="typeLabel(record.type)" data-testid="counseling-type-badge">
                        {{ typeShort(record.type) }}<span class="sr-only">: {{ typeLabel(record.type) }}</span>
                    </Badge>
                    <span class="font-medium" :class="{ 'text-muted-foreground': record.overturned || record.dcsPhase === 'expired' }" data-testid="counseling-kind-text">
                        {{ record.kindLabel ?? 'What it is for was not given' }}
                    </span>
                    <Badge :variant="recordBadge(record).tone" class="ml-auto" data-testid="counseling-state">{{ recordBadge(record).label }}</Badge>
                </div>

                <p class="text-xs text-muted-foreground" data-testid="counseling-meta">{{ meta(record).join(' · ') }}</p>

                <ul v-if="record.flags?.length" class="flex flex-col gap-0.5 text-xs text-warning" data-testid="counseling-flags">
                    <li v-for="flag in record.flags" :key="flag.key" class="flex items-start gap-1" :data-testid="`counseling-flag-${flag.key}`">
                        <PhWarning class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ flag.message }}
                    </li>
                </ul>
                <p v-if="record.kindAssumed" class="text-xs text-muted-foreground" data-testid="counseling-kind-assumed">
                    Filed before the portal asked what a record is for, so the other conduct rules apply to it.
                </p>
                <p v-if="record.overturned" class="flex items-start gap-1 text-xs text-muted-foreground" data-testid="counseling-overturned">
                    <PhArrowCounterClockwise class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="whitespace-pre-line">Overturned on {{ letterDate(record.overturned.date) }} by {{ record.overturned.by }}: {{ record.overturned.reason }}</span>
                </p>

                <div class="-ml-2 flex flex-wrap items-center gap-1">
                    <Button v-if="record.can?.continue" as-child variant="ghost" size="sm">
                        <Link :href="`/counseling/${record.id}`" data-testid="counseling-continue"><PhArrowRight aria-hidden="true" />Continue</Link>
                    </Button>
                    <Button v-else-if="record.can?.respond" as-child variant="ghost" size="sm">
                        <Link :href="`/counseling/${record.id}`" data-testid="counseling-respond"><PhChatText aria-hidden="true" />Respond</Link>
                    </Button>
                    <Button v-else-if="opens(record)" as-child variant="ghost" size="sm">
                        <Link :href="`/counseling/${record.id}`" data-testid="counseling-open"><PhEye aria-hidden="true" />Open</Link>
                    </Button>
                    <Button v-if="!isDraft(record)" variant="ghost" size="sm" :disabled="downloading === record.id" data-testid="counseling-download" @click="download(record)">
                        <PhDownloadSimple aria-hidden="true" />{{ record.hasSourceDocument ? 'Original PDF' : 'PDF' }}
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog v-model:open="starting">
            <DialogContent class="sm:max-w-md" data-testid="counseling-start-dialog">
                <DialogTitle class="text-base font-semibold">New DCS or LOR</DialogTitle>
                <DialogDescription class="text-[13px] text-muted-foreground">
                    Starts a draft that only you and the chain see. You write it step by step and share it with the trooper when it is ready.
                </DialogDescription>
                <form class="space-y-4" data-testid="counseling-start-form" @submit.prevent="submitStart">
                    <fieldset class="grid gap-1.5">
                        <legend class="mb-1 text-[13px] font-medium">Type</legend>
                        <label v-for="type in canFile" :key="type" class="inline-flex items-center gap-2 text-[13px]">
                            <input v-model="start.type" type="radio" name="counseling-start-type" class="size-4 accent-primary" :value="type" :data-testid="`counseling-start-type-${type}`" />
                            {{ typeLabel(type) }} ({{ typeShort(type) }})
                        </label>
                        <p v-if="start.errors.type" class="text-xs text-destructive">{{ start.errors.type }}</p>
                    </fieldset>
                    <div class="grid gap-1.5">
                        <Label for="counseling-start-kind">What it is for</Label>
                        <NativeSelect id="counseling-start-kind" v-model="start.kind" required :aria-invalid="!!start.errors.kind" data-testid="counseling-start-kind" @change="start.clearErrors('kind')">
                            <option value="" disabled>Choose one</option>
                            <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                        </NativeSelect>
                        <p v-if="start.errors.kind" class="text-xs text-destructive" data-testid="counseling-start-error-kind">{{ start.errors.kind }}</p>
                    </div>
                    <div v-if="hint.length" class="text-xs text-warning" data-testid="counseling-hint">
                        <p>Dated today, the record would be flagged:</p>
                        <ul class="mt-0.5 list-disc pl-4">
                            <li v-for="flag in hint" :key="flag.key" :data-testid="`counseling-hint-${flag.key}`">{{ flag.message }}</li>
                        </ul>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="start.processing" data-testid="counseling-start-submit">Start draft</Button>
                        <Button type="button" size="sm" variant="outline" @click="starting = false">Cancel</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="demoDocumentOpen">
            <DialogContent class="sm:max-w-md" data-testid="counseling-demo-dialog">
                <DialogTitle class="text-base font-semibold">Demo file</DialogTitle>
                <DialogDescription class="text-[13px] text-muted-foreground">This record's file is a demo file and will not be downloaded.</DialogDescription>
            </DialogContent>
        </Dialog>
    </MilpacPanel>
</template>
