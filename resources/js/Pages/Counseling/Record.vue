<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { PhArrowCounterClockwise, PhArrowUUpLeft, PhDownloadSimple, PhFilePdf, PhQuotes, PhScales } from '@phosphor-icons/vue';
import CounselingDeadlines from '@/components/counseling/CounselingDeadlines.vue';
import CounselingDraft from '@/components/counseling/CounselingDraft.vue';
import CounselingFormPreview from '@/components/counseling/CounselingFormPreview.vue';
import CounselingHeader from '@/components/counseling/CounselingHeader.vue';
import CounselingLetter from '@/components/counseling/CounselingLetter.vue';
import CounselingRespond from '@/components/counseling/CounselingRespond.vue';
import CounselingReview from '@/components/counseling/CounselingReview.vue';
import PmConsent from '@/components/counseling/PmConsent.vue';
import InlineNotice from '@/components/InlineNotice.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { COUNSELING_TYPES, consequenceBadges, zulu } from '@/lib/counseling';
import { downloadCounselingDocument } from '@/lib/counselingPdf';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { toast } from 'vue-sonner';

// One DCS or LOR (#565), for whoever may see it; the page adapts to what the server says the viewer may do (`can`) and
// where the record stands (`record.state`): the leader's case file while it is a draft, the trooper's response while it
// waits for them, the fields of a record kept with its source PDF to review, and otherwise the record read-only with
// its clocks, the appeal and its decision, and the chain's Withdraw and the RXO's Overturn. Hiding a control is
// cosmetic: every route checks again.
const props = defineProps({
    // `Milpac\CounselingRecordView::of()`.
    record: { type: Object, required: true },
    // `{ id, name, rank, billet }`: who the record is on.
    trooper: { type: Object, required: true },
    // Section 1 of the form: `{ date, trooper, authority }`, snapshotted once shared.
    section1: { type: Object, default: null },
    // The form's fields as saved (`CounselingDocument`), or null for a record kept with its source PDF.
    document: { type: Object, default: null },
    // What share still needs on a draft, field => message.
    missing: { type: [Object, Array], default: () => ({}) },
    appealAuthority: { type: String, default: null },
    can: { type: Object, required: true },
    // `{ allowed, consentUrl }`: whether the portal may send PMs from the viewer's forum account.
    pm: { type: Object, required: true },
    limits: { type: Object, required: true },
    kinds: { type: Array, required: true },
    status: { type: String, default: null },
});

const lor = computed(() => props.record.type === 'lor');
const mode = computed(() => {
    if (props.can.review) return 'review';
    if (props.can.edit && ['draft', 'invited'].includes(props.record.state)) return 'draft';
    if (props.can.respond) return 'respond';

    return 'read';
});

// ---- The document ----

const downloading = ref(false);

async function download() {
    downloading.value = true;
    try {
        await downloadCounselingDocument(props.record.id);
    } catch (error) {
        toast.error('The PDF could not be downloaded', { description: error?.message });
    } finally {
        downloading.value = false;
    }
}

// ---- Withdraw and overturn ----

const withdrawing = ref(false);
const withdrawForm = useForm({ reason: '' });

function withdraw(reason) {
    withdrawForm.reason = reason;
    withdrawForm.post(`/counseling/${props.record.id}/withdraw`, {
        preserveScroll: true,
        onSuccess: () => {
            withdrawing.value = false;
            toast.success('Withdrawn back to draft');
        },
        // A PM refusal is the page's to show (PmConsent), not the dialog's.
        onError: (errors) => {
            if (errors.pm || errors.pm_consent) withdrawing.value = false;
        },
        onHttpException: onHttpException('The record was not withdrawn', (message) => toast.error(message)),
    });
}

const overturning = ref(false);
const overturnForm = useForm({ reason: '' });

function overturn(reason) {
    overturnForm.reason = reason;
    overturnForm.post(`/counseling/${props.record.id}/overturn`, {
        preserveScroll: true,
        onSuccess: () => {
            overturning.value = false;
            toast.success('Letter of Reprimand overturned');
        },
        onHttpException: onHttpException('The letter was not overturned', (message) => toast.error(message)),
    });
}

// "From 2LT Varga.T, Platoon Leader", as Section 1 names the authority (snapshotted when it was shared).
const issuerLine = computed(() => {
    const authority = props.section1?.authority;
    const from = authority?.name ? [[authority.rank, authority.name].filter(Boolean).join(' '), authority.billet].filter(Boolean).join(', ') : props.record.issuer;

    return [`From ${from}`, props.record.kindLabel].filter(Boolean).join(' · ');
});
const original = computed(() => (props.record.appeal?.decided?.original ? consequenceBadges(props.record.appeal.decided.original) : []));
</script>

<template>
    <Head :title="`${record.typeLabel} · 7Cav Leadership Portal`" />

    <CounselingDraft
        v-if="mode === 'draft'"
        :record="record"
        :trooper="trooper"
        :section1="section1"
        :missing="missing"
        :appeal-authority="appealAuthority"
        :can="can"
        :pm="pm"
        :limits="limits"
        :kinds="kinds"
    />

    <!-- The trooper's own view while the record waits for their answer (respond-desktop, respond-phone). -->
    <div v-else-if="mode === 'respond'" class="mx-auto flex w-full max-w-3xl flex-col gap-4" data-testid="counseling-respond-page">
        <CounselingHeader :record="record" :trooper="trooper" :back="false" :eyebrow="`Shared with you ${zulu(record.sharedAt)}`" :subtitle="issuerLine">
            <template #actions>
                <Button variant="outline" size="sm" :disabled="downloading" data-testid="counseling-download" @click="download"><PhDownloadSimple aria-hidden="true" />Download PDF</Button>
            </template>
        </CounselingHeader>
        <CounselingRespond :record="record" :appeal-authority="appealAuthority" :limits="limits" :pm="pm">
            <CounselingLetter :record="record" title="What your leader wrote" />
        </CounselingRespond>
    </div>

    <!-- The record read-only (and, for a record kept with its source PDF, its fields to review). -->
    <div v-else class="flex flex-col gap-4" data-testid="counseling-read">
        <CounselingHeader :record="record" :trooper="trooper">
            <template #actions>
                <Button variant="outline" size="sm" :disabled="downloading" data-testid="counseling-download" @click="download">
                    <PhDownloadSimple aria-hidden="true" />{{ record.hasSourceDocument ? 'Original PDF' : 'Download PDF' }}
                </Button>
                <Button v-if="can.withdraw" variant="outline" size="sm" :disabled="!pm.allowed" data-testid="counseling-withdraw" @click="withdrawing = true">
                    <PhArrowUUpLeft aria-hidden="true" />Withdraw
                </Button>
                <Button v-if="can.overturn" variant="outline" size="sm" data-testid="counseling-overturn" @click="overturning = true">
                    <PhArrowCounterClockwise aria-hidden="true" />Overturn
                </Button>
            </template>
        </CounselingHeader>

        <PmConsent v-if="can.withdraw" :pm="pm" :consent-error="withdrawForm.errors.pm_consent" :pm-error="withdrawForm.errors.pm" sends="the withdrawal PM" />

        <InlineNotice v-if="record.overturned" :icon="PhArrowCounterClockwise" role="status" data-testid="counseling-overturned">
            Overturned on {{ forumDate(record.overturned.date) }} by {{ record.overturned.by }}: <span class="whitespace-pre-line">{{ record.overturned.reason }}</span>. It stays on file and no longer
            counts.
        </InlineNotice>
        <InlineNotice v-if="record.withdrawn && ['draft', 'invited'].includes(record.state)" :icon="PhArrowUUpLeft" tone="warning" role="status" data-testid="counseling-withdrawn">
            Withdrawn back to draft {{ zulu(record.withdrawn.at) }}: <span class="whitespace-pre-line">{{ record.withdrawn.reason }}</span>
        </InlineNotice>
        <InlineNotice v-if="record.needsReview && !can.review" :icon="PhFilePdf" tone="warning" role="status" data-testid="counseling-needs-review">
            Imported from a PDF and not reviewed yet: the original PDF is the record.
        </InlineNotice>

        <CounselingDeadlines v-if="record.sharedAt" :record="record" :appeal-authority="appealAuthority" />

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_26rem]">
            <div class="flex min-w-0 flex-col gap-4">
                <CounselingReview v-if="mode === 'review'" :record="record" :kinds="kinds" :limits="limits" />

                <!-- The appeal and its decision. -->
                <section v-if="record.appeal" class="overflow-hidden rounded-xl border border-border bg-card text-[13px]" data-testid="counseling-appeal">
                    <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                        <PhScales class="size-4 text-muted-foreground" aria-hidden="true" />
                        <h2 class="text-[15px] font-semibold">Appeal</h2>
                        <Badge v-if="record.appeal.pending" variant="warning" class="ml-auto">Waiting for the decision</Badge>
                        <Badge v-else-if="record.appeal.decided" variant="info" class="ml-auto">{{ record.appeal.decided.outcomeLabel }}</Badge>
                        <Badge v-else-if="lor" variant="secondary" class="ml-auto">Lapsed: it stands as written</Badge>
                    </div>
                    <div class="flex flex-col gap-3 px-4 py-3">
                        <figure class="rounded-md bg-muted/50 p-3">
                            <figcaption class="flex items-center gap-2 text-xs text-muted-foreground">
                                <PhQuotes class="size-4 shrink-0" aria-hidden="true" />The trooper's reason, {{ zulu(record.appeal.at) }}
                            </figcaption>
                            <blockquote class="mt-1 pl-6 whitespace-pre-line" data-testid="appeal-reason">{{ record.appeal.reason }}</blockquote>
                        </figure>
                        <p v-if="record.appeal.pending" class="text-muted-foreground">{{ appealAuthority ?? 'The commander one level up' }} decides by {{ zulu(record.appeal.dueAt) }}.</p>
                        <div v-if="record.appeal.decided" data-testid="appeal-decision">
                            <p>
                                <span class="font-medium">{{ record.appeal.decided.outcomeLabel }}</span>, by {{ record.appeal.decided.by }} {{ zulu(record.appeal.decided.at) }}.
                            </p>
                            <p class="mt-1 whitespace-pre-line">{{ record.appeal.decided.reason }}</p>
                            <p v-if="original.length" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                Consequence before the amendment: <Badge v-for="badge in original" :key="badge" variant="outline">{{ badge }}</Badge>
                            </p>
                        </div>
                    </div>
                </section>

                <CounselingLetter v-if="mode !== 'review'" :record="record" trooper-part />
                <p v-if="record.sharedAt && !record.respondedAt && record.state === 'shared'" class="text-[13px] text-muted-foreground" data-testid="counseling-waiting">
                    Waiting for {{ trooper.name }} to add their part, until {{ zulu(record.respondBy) }}.
                </p>
                <p v-else-if="record.state === 'no_response'" class="text-[13px] text-muted-foreground" data-testid="counseling-no-response">
                    {{ trooper.name }} did not answer by {{ zulu(record.respondBy) }}, so it stands as written.
                </p>
            </div>

            <aside class="flex min-w-0 flex-col gap-4" aria-label="Document">
                <section class="overflow-hidden rounded-xl border border-border bg-card" data-testid="counseling-document">
                    <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                        <h2 class="text-[15px] font-semibold">Document</h2>
                        <Badge variant="outline">{{ COUNSELING_TYPES[record.type]?.form }}</Badge>
                    </div>
                    <div v-if="record.hasSourceDocument" class="px-4 py-3 text-[13px] text-muted-foreground">
                        This record is kept with the PDF it was filed with. <button type="button" class="text-primary hover:underline" @click="download">Open the original PDF</button>.
                    </div>
                    <div v-else class="bg-muted/40 p-3">
                        <CounselingFormPreview :type="record.type" :date="section1?.date ?? record.date" :section1="section1" :form="record.form" :outcome="record.appeal?.decided?.outcome ?? null" />
                    </div>
                </section>
            </aside>
        </div>

        <ReasonDialog
            v-model:open="withdrawing"
            title="Withdraw back to draft?"
            :description="`${trooper.name} is told in a PM from your forum account. The record goes back to a draft you can change and share again.`"
            confirm-label="Withdraw"
            pending-label="Withdrawing…"
            :processing="withdrawForm.processing"
            :error="withdrawForm.errors.reason"
            testid="withdraw-dialog"
            @confirm="withdraw"
        />
        <ReasonDialog
            v-model:open="overturning"
            title="Overturn this Letter of Reprimand?"
            description="It stays on file, marked overturned with your reason, and no longer counts toward the counseling rules."
            confirm-label="Overturn"
            pending-label="Overturning…"
            :processing="overturnForm.processing"
            :error="overturnForm.errors.reason"
            testid="overturn-dialog"
            @confirm="overturn"
        />
    </div>
</template>
