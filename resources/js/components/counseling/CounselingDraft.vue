<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { PhArrowLeft, PhCheck, PhClock, PhDownloadSimple, PhFilePdf, PhFloppyDisk, PhListNumbers, PhPaperPlaneTilt, PhPencilSimple, PhTrash } from '@phosphor-icons/vue';
import ConsequenceFields from '@/components/counseling/ConsequenceFields.vue';
import CounselingDeadlines from '@/components/counseling/CounselingDeadlines.vue';
import CounselingFormPreview from '@/components/counseling/CounselingFormPreview.vue';
import CounselingHeader from '@/components/counseling/CounselingHeader.vue';
import PmConsent from '@/components/counseling/PmConsent.vue';
import InlineNotice from '@/components/InlineNotice.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useNow } from '@/composables/useNow';
import { COUNSELING_TYPES, consequenceBadges, documentPayload, draftPayload, fieldLimit, leaderForm, letterDate, shareChecklist, timeLeft, todayUtc, zulu } from '@/lib/counseling';
import { fillCounselingPdf, saveFile } from '@/lib/counselingPdf';
import { onHttpException } from '@/lib/httpError';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from 'vue-sonner';

// The leader's case file for a draft DCS or LOR (#565): the wiki procedure as steps, the form filled live beside them,
// and every clock that will run. On a desktop the steps sit beside the document; on a phone it is one step at a time
// with Back, Save draft and Continue at the bottom, and the document on its own tab. Any step saves the whole draft
// (PATCH counseling.update) and Continue saves too; nothing reaches the trooper until the explicit Share step, which
// the server refuses while anything is missing (`missing`, judged on the saved draft). Leaving with unsaved changes asks
// first.
const props = defineProps({
    record: { type: Object, required: true },
    trooper: { type: Object, required: true },
    section1: { type: Object, default: null },
    missing: { type: [Object, Array], default: () => ({}) },
    appealAuthority: { type: String, default: null },
    can: { type: Object, required: true },
    pm: { type: Object, required: true },
    limits: { type: Object, required: true },
    kinds: { type: Array, required: true },
});

const draft = useForm({
    type: props.record.type,
    kind: props.record.kind ?? '',
    counseled_on: props.record.date ?? '',
    meeting_confirmed: !!props.record.meeting?.confirmed,
    form: leaderForm(props.record.form),
});
const lor = computed(() => draft.type === 'lor');
const authority = computed(() => COUNSELING_TYPES[draft.type].authority.toLowerCase());
const missingNow = computed(() => (props.missing && !Array.isArray(props.missing) ? props.missing : {}));
const invited = computed(() => !!props.record.meeting?.invitedAt);
const now = useNow();
const saved = computed(() => timeAgo(props.record.savedAt, now.value));
const kindLabel = computed(() => props.kinds.find((kind) => kind.value === draft.kind)?.label ?? 'What it is for not chosen');

// "Needed before you can share" under a field the saved draft still lacks and the leader has not filled in since.
const needed = (key, value) => key in missingNow.value && !value;

// ---- Steps ----

const steps = computed(() => {
    const f = draft.form;
    const list = [
        {
            key: 'type',
            title: 'Trooper and type',
            summary: [props.trooper.rank, props.trooper.name].filter(Boolean).join(' ') + ` · ${COUNSELING_TYPES[draft.type].label} · ${kindLabel.value}`,
            done: !!draft.kind,
        },
        { key: 'what', title: 'What happened', summary: f.infraction.split('\n')[0] || 'The infraction and the regulation it breaks', done: !!(f.infraction.trim() && f.regulation.trim()) },
        {
            key: 'invite',
            title: 'Meeting invite',
            summary: invited.value ? `PM sent from your forum account ${zulu(props.record.meeting.invitedAt)}` : lor.value ? 'Three times you can meet, in Zulu' : 'Optional for a DCS',
            done: invited.value,
            optional: !lor.value,
        },
        {
            key: 'meeting',
            title: 'The meeting',
            summary: draft.meeting_confirmed && draft.counseled_on ? `Held ${letterDate(draft.counseled_on)} · plan of action agreed` : 'Write it up after you have met',
            done: !!(draft.counseled_on && f.discussion.trim() && f.plan_authority.trim() && draft.meeting_confirmed),
        },
    ];
    if (lor.value) {
        const badges = consequenceBadges(f);
        list.push({
            key: 'consequence',
            title: 'Consequence',
            summary: badges.join(', ') || 'The recommended consequence',
            done: f.consequences.length > 0 && (!f.consequences.includes('removal') || !!f.removal),
        });
    }
    list.push({ key: 'share', title: 'Review and share', summary: 'Locks your part and sends the After-Action PM', done: false });

    return list;
});

const firstOpen = steps.value.findIndex((step) => !step.done && !step.optional);
const current = ref(firstOpen === -1 ? 0 : firstOpen);
const step = computed(() => steps.value[current.value]);
const doneCount = computed(() => steps.value.filter((s) => s.done).length);
// Switching to a DCS drops the Consequence step: stay on a step that exists.
watch(
    () => steps.value.length,
    (length) => {
        if (current.value >= length) current.value = length - 1;
    },
);

function go(index) {
    current.value = Math.max(0, Math.min(steps.value.length - 1, index));
    document.getElementById(`step-${steps.value[current.value].key}`)?.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
}

// The step an error belongs to, so a refused save opens it.
const STEP_OF = {
    type: 'type',
    kind: 'type',
    'form.infraction': 'what',
    'form.regulation': 'what',
    counseled_on: 'meeting',
    meeting_confirmed: 'meeting',
    'form.discussion': 'meeting',
    'form.plan_authority': 'meeting',
    'form.plan_other': 'meeting',
    'form.comments_authority': 'meeting',
    'form.comments_other': 'meeting',
    'form.consequences': 'consequence',
    'form.removal': 'consequence',
    'form.special_punishments': 'consequence',
};

// ---- Saving ----

function save(then = null) {
    if (!draft.isDirty) {
        then?.();
        return;
    }
    draft.transform(draftPayload).patch(`/counseling/${props.record.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            draft.defaults();
            then ? then() : toast.success('Draft saved');
        },
        onError: (errors) => {
            const target = Object.keys(errors).map((key) => STEP_OF[key]).find(Boolean);
            const index = steps.value.findIndex((s) => s.key === target);
            if (index !== -1) go(index);
            toast.error('The draft was not saved', { description: 'Check the field marked in red.' });
        },
        onHttpException: onHttpException('The draft was not saved', (message) => toast.error(message)),
    });
}

const next = () => save(() => go(current.value + 1));
const back = () => go(current.value - 1);

// ---- Meeting invite ----

const invite = useForm({ windows: props.record.meeting?.windows?.length === 3 ? [...props.record.meeting.windows] : ['', '', ''] });

function sendInvite() {
    if (invite.windows.some((window) => !window.trim())) {
        invite.setError('windows', 'Give three times you can meet, in Zulu.');
        return;
    }
    invite.post(`/counseling/${props.record.id}/invite`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toast.success('Meeting invite sent'),
        onHttpException: onHttpException('The invite was not sent', (message) => toast.error(message)),
    });
}
const inviteError = computed(() => invite.errors.windows ?? Object.entries(invite.errors).find(([key]) => key.startsWith('windows.'))?.[1] ?? null);

// ---- Share ----

const checklist = computed(() => shareChecklist(draft.type, missingNow.value));
const openItems = computed(() => checklist.value.filter((item) => !item.done));
const share = useForm({});
const shareErrors = computed(() => Object.entries(share.errors).filter(([key]) => !['pm', 'pm_consent'].includes(key)));

function postShare() {
    if (openItems.value.length) {
        toast.error('Not shared yet', { description: `${openItems.value.length} ${openItems.value.length === 1 ? 'thing is' : 'things are'} still missing.` });
        return;
    }
    share.post(`/counseling/${props.record.id}/share`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toast.success('Shared with the trooper'),
        onHttpException: onHttpException('The record was not shared', (message) => toast.error(message)),
    });
}

// Unsaved changes are saved first, so what is shared is what is on screen.
const shareNow = () => save(postShare);

// ---- Delete ----

const deleting = ref(false);
const destroy = useForm({});

function confirmDelete() {
    destroy.delete(`/counseling/${props.record.id}`, {
        onSuccess: () => toast.success('Draft deleted'),
        onFinish: () => (deleting.value = false),
        onHttpException: onHttpException('The draft was not deleted', (message) => toast.error(message)),
    });
}

// ---- The document ----

const live = computed(() => ({ type: draft.type, date: draft.counseled_on || null, section1: props.section1, form: draft.form, trooper: props.trooper }));
const downloading = ref(false);

async function draftPdf() {
    downloading.value = true;
    try {
        const payload = documentPayload({ ...live.value, version: props.record.version });
        saveFile(await fillCounselingPdf(payload), payload.fileName);
    } catch (error) {
        toast.error('The PDF could not be made', { description: error?.message ?? 'Try again in a moment.' });
    } finally {
        downloading.value = false;
    }
}

const phoneTab = ref('steps');
const meetingLeft = computed(() => timeLeft(props.record.meeting?.closesAt, now.value));

// ---- Leaving with unsaved changes ----

const LEAVE = 'This draft has changes that are not saved. Leave anyway?';
const unsaved = () => props.can.edit && draft.isDirty;

function onBeforeUnload(event) {
    if (!unsaved()) return;
    event.preventDefault();
    event.returnValue = '';
}

let stopGuard = null;
onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    stopGuard = router.on?.('before', (event) => {
        const visit = event.detail.visit;
        // The page's own saves and steps are writes to this record; only leaving is guarded.
        if (visit.method !== 'get' || !unsaved()) return;

        return window.confirm(LEAVE);
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    stopGuard?.();
});

const typeChoices = computed(() =>
    Object.entries(COUNSELING_TYPES).map(([value, type]) => ({
        value,
        label: `${type.label} (${type.short})`,
        // Changing a DCS into an LOR needs the right to file one; an LOR already on the draft stays choosable.
        disabled: value === 'lor' && !props.can.fileLor && props.record.type !== 'lor',
    })),
);
</script>

<template>
    <div class="flex flex-col gap-4 pb-24 lg:pb-0" data-testid="counseling-draft">
        <CounselingHeader :record="record" :trooper="trooper">
            <template #actions>
                <span v-if="saved" class="text-[13px] text-muted-foreground" data-testid="draft-saved">Saved {{ saved }}</span>
                <Button variant="outline" size="sm" :disabled="draft.processing" data-testid="draft-save" @click="save()"><PhFloppyDisk aria-hidden="true" />Save draft</Button>
                <Button v-if="can.delete" variant="ghost" size="sm" class="text-destructive hover:text-destructive" data-testid="draft-delete" @click="deleting = true">
                    <PhTrash aria-hidden="true" />Delete draft
                </Button>
            </template>
        </CounselingHeader>

        <InlineNotice v-if="record.withdrawn" :icon="PhArrowLeft" tone="warning" role="status" data-testid="draft-withdrawn">
            Withdrawn back to draft {{ zulu(record.withdrawn.at) }}: <span class="whitespace-pre-line">{{ record.withdrawn.reason }}</span>
        </InlineNotice>

        <InlineNotice v-if="meetingLeft" :icon="PhClock" tone="warning" class="sm:hidden" role="status" data-testid="draft-meeting-banner">
            Meeting window closes <strong>{{ zulu(record.meeting.closesAt) }}</strong>, in {{ meetingLeft }}.
        </InlineNotice>
        <CounselingDeadlines :record="record" :appeal-authority="appealAuthority" class="hidden sm:grid" />

        <Tabs v-model="phoneTab" class="lg:hidden">
            <TabsList aria-label="Draft views">
                <TabsTrigger value="steps" data-testid="tab-steps"><PhListNumbers aria-hidden="true" />Steps</TabsTrigger>
                <TabsTrigger value="document" data-testid="tab-document"><PhFilePdf aria-hidden="true" />Document</TabsTrigger>
            </TabsList>
        </Tabs>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_26rem]">
            <!-- The steps. On a phone only the current one shows, with the progress above it. -->
            <ol class="flex min-w-0 flex-col gap-3" :class="{ 'hidden lg:flex': phoneTab !== 'steps' }" aria-label="Steps">
                <li class="lg:hidden" aria-hidden="true">
                    <div class="flex items-center justify-between text-[13px]">
                        <span class="font-medium">Step {{ current + 1 }} of {{ steps.length }} · {{ step.title }}</span>
                        <span class="text-muted-foreground">{{ doneCount }} done</span>
                    </div>
                    <div class="mt-2 h-1.5 rounded-full bg-muted"><div class="h-full rounded-full bg-foreground" :style="{ width: `${((current + 1) / steps.length) * 100}%` }" /></div>
                </li>

                <li
                    v-for="(item, index) in steps"
                    :id="`step-${item.key}`"
                    :key="item.key"
                    class="scroll-mt-4 overflow-hidden rounded-xl border bg-card"
                    :class="[index === current ? 'border-border border-l-4 border-l-primary' : 'hidden border-border lg:block']"
                    :aria-current="index === current ? 'step' : undefined"
                    :data-testid="`step-${item.key}`"
                >
                    <div class="flex items-start gap-3 px-4 py-3" :class="{ 'border-b border-border': index === current }">
                        <span
                            class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full border text-[13px] font-medium"
                            :class="index === current ? 'border-primary bg-primary text-primary-foreground' : item.done ? 'border-success/40 bg-success/8 text-success' : 'border-border text-muted-foreground'"
                            aria-hidden="true"
                        >
                            <PhCheck v-if="item.done && index !== current" class="size-3.5" />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[15px] font-semibold" :class="{ 'text-muted-foreground': index > current && !item.done }">
                                {{ item.title }}<span class="sr-only">{{ item.done ? ' (done)' : '' }}</span>
                            </h2>
                            <p class="truncate text-[13px] text-muted-foreground">
                                <template v-if="index === current && item.key === 'meeting'">Write it up after you have met. The trooper adds their part once you share.</template>
                                <template v-else>{{ item.summary }}</template>
                            </p>
                        </div>
                        <Badge v-if="item.key === 'invite' && invited" variant="success">Sent</Badge>
                        <Button v-else-if="index !== current" variant="ghost" size="sm" :data-testid="`step-edit-${item.key}`" @click="go(index)">
                            <PhPencilSimple aria-hidden="true" />{{ item.done ? 'Edit' : 'Open' }}<span class="sr-only">: {{ item.title }}</span>
                        </Button>
                    </div>

                    <div v-if="index === current" class="flex flex-col gap-4 px-4 py-4">
                        <!-- 1 · Trooper and type -->
                        <template v-if="item.key === 'type'">
                            <fieldset class="grid gap-1.5" data-testid="draft-type">
                                <legend class="mb-1 text-[13px] font-medium">Type</legend>
                                <label v-for="choice in typeChoices" :key="choice.value" class="inline-flex items-center gap-2 text-[13px]" :class="{ 'opacity-50': choice.disabled }">
                                    <input v-model="draft.type" type="radio" name="draft-type" class="size-4 accent-primary" :value="choice.value" :disabled="choice.disabled" :data-testid="`draft-type-${choice.value}`" />
                                    {{ choice.label }}
                                </label>
                                <p v-if="!can.fileLor && record.type !== 'lor'" class="text-xs text-muted-foreground">Only a leader who may issue a Letter of Reprimand can make this one.</p>
                                <p v-if="draft.errors.type" class="text-xs text-destructive">{{ draft.errors.type }}</p>
                            </fieldset>
                            <div class="grid gap-1.5">
                                <Label for="draft-kind">What it is for</Label>
                                <NativeSelect id="draft-kind" v-model="draft.kind" required :aria-invalid="!!draft.errors.kind" data-testid="draft-kind">
                                    <option value="" disabled>Choose one</option>
                                    <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                                </NativeSelect>
                                <p v-if="draft.errors.kind" class="text-xs text-destructive">{{ draft.errors.kind }}</p>
                            </div>
                            <div v-if="section1" class="overflow-x-auto">
                                <table class="w-full text-left text-[13px]" data-testid="draft-section-1">
                                    <caption class="mb-1 text-left text-xs text-muted-foreground">Section 1, from milpac. It stays live while this is a draft and is fixed when you share.</caption>
                                    <tbody>
                                        <tr>
                                            <th scope="row" class="py-1 pr-3 font-normal text-muted-foreground">Trooper</th>
                                            <td class="py-1">{{ [section1.trooper.rank, section1.trooper.name, section1.trooper.billet].filter(Boolean).join(' · ') }}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="py-1 pr-3 font-normal text-muted-foreground">{{ COUNSELING_TYPES[draft.type].authority }}</th>
                                            <td class="py-1">{{ [section1.authority.rank, section1.authority.name, section1.authority.billet].filter(Boolean).join(' · ') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <!-- 2 · What happened -->
                        <template v-else-if="item.key === 'what'">
                            <div class="grid gap-1.5">
                                <Label for="draft-infraction">Evidenced infraction and previous instances</Label>
                                <Textarea id="draft-infraction" v-model="draft.form.infraction" rows="4" :maxlength="fieldLimit('infraction', limits)" aria-describedby="draft-infraction-help" :aria-invalid="!!draft.errors['form.infraction']" data-testid="draft-infraction" />
                                <p id="draft-infraction-help" class="text-xs text-muted-foreground">Dates and what was missed or done, and any earlier counseling for it.</p>
                                <p v-if="draft.errors['form.infraction']" class="text-xs text-destructive">{{ draft.errors['form.infraction'] }}</p>
                                <p v-else-if="needed('infraction', draft.form.infraction)" class="text-xs text-destructive">Needed before you can share.</p>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="draft-regulation">Associated regulation, order or SOP</Label>
                                <Textarea id="draft-regulation" v-model="draft.form.regulation" rows="2" :maxlength="fieldLimit('regulation', limits)" :aria-invalid="!!draft.errors['form.regulation']" data-testid="draft-regulation" />
                                <p v-if="draft.errors['form.regulation']" class="text-xs text-destructive">{{ draft.errors['form.regulation'] }}</p>
                                <p v-else-if="needed('regulation', draft.form.regulation)" class="text-xs text-destructive">Needed before you can share.</p>
                            </div>
                        </template>

                        <!-- 3 · Meeting invite -->
                        <template v-else-if="item.key === 'invite'">
                            <div v-if="invited" class="flex flex-col gap-2 text-[13px]" data-testid="invite-sent">
                                <p>
                                    Sent {{ zulu(record.meeting.invitedAt) }}. The meeting window closes <strong>{{ zulu(record.meeting.closesAt) }}</strong>.
                                </p>
                                <p class="text-xs text-muted-foreground">The times you offered:</p>
                                <ul class="list-disc pl-5">
                                    <li v-for="(window, i) in record.meeting.windows" :key="i">{{ window }}</li>
                                </ul>
                            </div>
                            <template v-else>
                                <p class="text-[13px] text-muted-foreground">
                                    Give three times you can meet in the next 72 hours, in Zulu. The portal sends them to {{ trooper.name }} in a PM from your forum account.
                                    <template v-if="!lor"> Optional for a DCS: skip it if you reached them by voice within 24 hours.</template>
                                </p>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div v-for="(_, i) in invite.windows" :key="i" class="grid gap-1.5">
                                        <Label :for="`invite-window-${i}`">Option {{ i + 1 }} (Zulu)</Label>
                                        <Input :id="`invite-window-${i}`" v-model="invite.windows[i]" :maxlength="limits.window" placeholder="Thu 29 Oct 2000Z" :disabled="!can.invite" :data-testid="`invite-window-${i}`" />
                                    </div>
                                </div>
                                <p v-if="inviteError" class="text-xs text-destructive" data-testid="invite-error">{{ inviteError }}</p>
                                <PmConsent :pm="pm" :consent-error="invite.errors.pm_consent" :pm-error="invite.errors.pm" sends="the meeting invite" />
                                <div>
                                    <Button variant="outline" size="sm" :disabled="!can.invite || !pm.allowed || invite.processing" data-testid="invite-send" @click="sendInvite">
                                        <PhPaperPlaneTilt aria-hidden="true" />Send invite
                                    </Button>
                                </div>
                            </template>
                        </template>

                        <!-- 4 · The meeting -->
                        <template v-else-if="item.key === 'meeting'">
                            <div class="grid gap-1.5 sm:max-w-xs">
                                <Label for="draft-date">Date of Letter (the meeting date)</Label>
                                <Input id="draft-date" v-model="draft.counseled_on" type="date" :max="todayUtc()" :aria-invalid="!!draft.errors.counseled_on" data-testid="draft-date" />
                                <p v-if="draft.errors.counseled_on" class="text-xs text-destructive">{{ draft.errors.counseled_on }}</p>
                                <p v-else-if="needed('counseled_on', draft.counseled_on)" class="text-xs text-destructive">Needed before you can share.</p>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="draft-discussion">Notes of discussion</Label>
                                <Textarea id="draft-discussion" v-model="draft.form.discussion" rows="4" :maxlength="fieldLimit('discussion', limits)" placeholder="What was said, in your words. The trooper reads this." :aria-invalid="!!draft.errors['form.discussion']" data-testid="draft-discussion" />
                                <p v-if="draft.errors['form.discussion']" class="text-xs text-destructive">{{ draft.errors['form.discussion'] }}</p>
                                <p v-else-if="needed('discussion', draft.form.discussion)" class="text-xs text-destructive">Needed before you can share.</p>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <Label for="draft-plan-authority">Agreed plan of action: {{ authority }}</Label>
                                    <Textarea id="draft-plan-authority" v-model="draft.form.plan_authority" rows="3" :maxlength="fieldLimit('plan_authority', limits)" placeholder="What you will do" :aria-invalid="!!draft.errors['form.plan_authority']" data-testid="draft-plan-authority" />
                                    <p v-if="draft.errors['form.plan_authority']" class="text-xs text-destructive">{{ draft.errors['form.plan_authority'] }}</p>
                                    <p v-else-if="needed('plan_authority', draft.form.plan_authority)" class="text-xs text-destructive">Needed before you can share.</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="draft-plan-other">Agreed plan of action: misc / others</Label>
                                    <Textarea id="draft-plan-other" v-model="draft.form.plan_other" rows="3" :maxlength="fieldLimit('plan_other', limits)" placeholder="Optional, e.g. the section leader" data-testid="draft-plan-other" />
                                    <p v-if="draft.errors['form.plan_other']" class="text-xs text-destructive">{{ draft.errors['form.plan_other'] }}</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="draft-comments-authority">Any comments: {{ authority }}</Label>
                                    <Textarea id="draft-comments-authority" v-model="draft.form.comments_authority" rows="2" :maxlength="fieldLimit('comments_authority', limits)" placeholder="Optional" data-testid="draft-comments-authority" />
                                    <p v-if="draft.errors['form.comments_authority']" class="text-xs text-destructive">{{ draft.errors['form.comments_authority'] }}</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="draft-comments-other">Any comments: misc / others</Label>
                                    <Textarea id="draft-comments-other" v-model="draft.form.comments_other" rows="2" :maxlength="fieldLimit('comments_other', limits)" placeholder="Optional" data-testid="draft-comments-other" />
                                    <p v-if="draft.errors['form.comments_other']" class="text-xs text-destructive">{{ draft.errors['form.comments_other'] }}</p>
                                </div>
                            </div>
                            <div>
                                <label class="inline-flex items-start gap-2 text-[13px]">
                                    <input v-model="draft.meeting_confirmed" type="checkbox" class="mt-0.5 size-4 accent-primary" data-testid="draft-meeting-confirmed" />
                                    I met {{ trooper.name }} and went through this {{ lor ? 'letter' : 'statement' }} with them.
                                </label>
                                <p v-if="needed('meeting_confirmed', draft.meeting_confirmed)" class="mt-1 text-xs text-destructive">Needed before you can share.</p>
                            </div>
                        </template>

                        <!-- 5 · Consequence (LOR) -->
                        <template v-else-if="item.key === 'consequence'">
                            <ConsequenceFields
                                id="draft"
                                v-model:consequences="draft.form.consequences"
                                v-model:removal="draft.form.removal"
                                v-model:special-punishments="draft.form.special_punishments"
                                :max="limits.long"
                                :errors="{ ...draft.errors, ...(needed('consequences', draft.form.consequences.length) ? { consequences: 'Needed before you can share.' } : {}) }"
                            />
                        </template>

                        <!-- 6 · Review and share -->
                        <template v-else-if="item.key === 'share'">
                            <div class="text-[13px]" data-testid="share-step">
                                <p class="font-medium">Before you can share {{ checklist.length - openItems.length }}/{{ checklist.length }}</p>
                                <ul class="mt-2 flex flex-col gap-1" data-testid="share-blocked">
                                    <li v-for="entry in openItems" :key="entry.key" class="text-destructive" :data-testid="`share-missing-${entry.key}`">{{ entry.message ?? entry.label }}</li>
                                </ul>
                                <p v-if="draft.isDirty" class="mt-2 text-xs text-muted-foreground">Your latest changes are saved first.</p>
                            </div>
                            <p class="text-[13px] text-muted-foreground">
                                Sharing locks your part and sends the After-Action PM from your forum account to {{ trooper.name }}, S1 and the oversight officer. You can withdraw it
                                back to a draft, with a reason, until it locks 72 hours later.
                            </p>
                            <ul v-if="shareErrors.length" class="flex flex-col gap-1 text-xs text-destructive" role="alert" data-testid="share-errors">
                                <li v-for="[key, message] in shareErrors" :key="key">{{ message }}</li>
                            </ul>
                            <PmConsent :pm="pm" :consent-error="share.errors.pm_consent" :pm-error="share.errors.pm" sends="the After-Action PM" />
                        </template>

                        <!-- Desktop footer; a phone has the bar at the bottom of the screen. -->
                        <div class="hidden flex-wrap items-center gap-2 border-t border-border pt-4 lg:flex">
                            <Button variant="ghost" size="sm" :disabled="draft.processing" @click="save()"><PhFloppyDisk aria-hidden="true" />Save draft</Button>
                            <div class="ml-auto flex gap-2">
                                <Button v-if="current > 0" variant="outline" size="sm" data-testid="step-back" @click="back">Back</Button>
                                <Button
                                    v-if="item.key === 'share'"
                                    size="sm"
                                    :disabled="!can.share || !pm.allowed || share.processing || draft.processing || (openItems.length > 0 && !draft.isDirty)"
                                    data-testid="share-submit"
                                    @click="shareNow"
                                >
                                    <PhPaperPlaneTilt aria-hidden="true" />Share with trooper
                                </Button>
                                <Button v-else size="sm" :disabled="draft.processing" data-testid="step-continue" @click="next">Continue</Button>
                            </div>
                        </div>
                    </div>
                </li>
            </ol>

            <!-- The document, the share checklist and who sees the record. On a phone, the Document tab. -->
            <aside class="flex min-w-0 flex-col gap-4 lg:sticky lg:top-4" :class="{ 'hidden lg:flex': phoneTab !== 'document' }" aria-label="Document">
                <section class="overflow-hidden rounded-xl border border-border bg-card" data-testid="draft-document">
                    <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                        <h2 class="text-[15px] font-semibold">Document</h2>
                        <Badge variant="outline">{{ COUNSELING_TYPES[draft.type].form }}</Badge>
                        <Button variant="ghost" size="sm" class="ml-auto" :disabled="downloading" data-testid="draft-pdf" @click="draftPdf"><PhDownloadSimple aria-hidden="true" />Draft PDF</Button>
                    </div>
                    <div class="bg-muted/40 p-3"><CounselingFormPreview :type="draft.type" :date="draft.counseled_on || null" :section1="section1" :form="draft.form" /></div>
                    <p class="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                        Filled from what is on screen, saved or not. The PDF is made in your browser when someone opens it; the portal keeps no copy.
                    </p>
                </section>

                <section class="overflow-hidden rounded-xl border border-border bg-card" data-testid="share-checklist">
                    <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                        <h2 class="text-[15px] font-semibold">Before you can share</h2>
                        <Badge variant="secondary" class="ml-auto rounded-full tabular-nums" :aria-label="`${checklist.length - openItems.length} of ${checklist.length} done`">
                            {{ checklist.length - openItems.length }} / {{ checklist.length }}
                        </Badge>
                    </div>
                    <ul class="flex flex-col gap-2 px-4 py-3 text-[13px]">
                        <li v-for="entry in checklist" :key="entry.key" class="flex items-center gap-2" :data-done="entry.done ? 'true' : 'false'" :data-testid="`checklist-${entry.key}`">
                            <span
                                class="grid size-4 shrink-0 place-items-center rounded-full border"
                                :class="entry.done ? 'border-success bg-success text-white' : 'border-muted-foreground/50'"
                                aria-hidden="true"
                            >
                                <PhCheck v-if="entry.done" class="size-2.5" weight="bold" />
                            </span>
                            <span :class="{ 'text-muted-foreground': !entry.done }">{{ entry.label }}</span>
                            <span class="sr-only">{{ entry.done ? '(done)' : '(still missing)' }}</span>
                        </li>
                    </ul>
                    <p class="border-t border-border px-4 py-2 text-xs text-muted-foreground">As last saved.</p>
                </section>

                <section class="overflow-hidden rounded-xl border border-border bg-card text-[13px]" data-testid="who-can-see">
                    <h2 class="border-b border-border px-4 py-3 text-[15px] font-semibold">Who can see this</h2>
                    <dl class="flex flex-col gap-3 px-4 py-3">
                        <div>
                            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Now (draft)</dt>
                            <dd>You, your chain of command above {{ trooper.name }}, and Admins.</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">After you share</dt>
                            <dd v-if="lor">Also {{ trooper.name }}, their section leaders, S1 and the oversight officer. Permanent for an LOR.</dd>
                            <dd v-else>
                                Also {{ trooper.name }} and their section leaders for 30 days, S1 and the oversight officer. Platoon Leader and up see it until it expires after 90 days.
                            </dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>

        <!-- A phone's step bar: Back, Save draft and Continue (or Share), always in reach. -->
        <div class="fixed inset-x-0 bottom-0 z-30 flex gap-2 border-t border-border bg-background/95 px-4 py-3 backdrop-blur lg:hidden" data-testid="phone-step-bar">
            <Button variant="outline" size="icon" :disabled="current === 0" aria-label="Back" @click="back"><PhArrowLeft aria-hidden="true" /></Button>
            <Button variant="outline" size="icon" :disabled="draft.processing" aria-label="Save draft" @click="save()"><PhFloppyDisk aria-hidden="true" /></Button>
            <Button
                v-if="step.key === 'share'"
                class="flex-1"
                :disabled="!can.share || !pm.allowed || share.processing || draft.processing || (openItems.length > 0 && !draft.isDirty)"
                @click="shareNow"
            >
                Share with trooper
            </Button>
            <Button v-else class="flex-1" :disabled="draft.processing" @click="next">Continue</Button>
        </div>

        <ReasonDialog
            v-model:open="deleting"
            title="Delete this draft?"
            :description="`The draft on ${trooper.name} is deleted for good. Nothing was shared with them${invited ? ', apart from the meeting invite' : ''}.`"
            confirm-label="Delete draft"
            pending-label="Deleting…"
            :require-reason="false"
            destructive
            :processing="destroy.processing"
            testid="draft-delete-dialog"
            @confirm="confirmDelete"
        />
    </div>
</template>
