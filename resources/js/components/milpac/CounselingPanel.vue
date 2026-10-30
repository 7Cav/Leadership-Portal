<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { PhArrowCounterClockwise, PhChatText, PhDownloadSimple, PhEye, PhFilePdf, PhLock, PhPencilSimple, PhPlus, PhTrash, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import MilpacPanel from '@/components/milpac/MilpacPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import {
    APPEAL_STATEMENT_MAX,
    APPEAL_WORDS,
    COUNSELING_KINDS,
    COUNSELING_TYPES,
    DOCUMENT_SOURCES,
    DRIVE_STATUS,
    FORM_LIMITS,
    LOR_APPEALS,
    LOR_CONSEQUENCES,
    LOR_REMOVALS,
    counselingFormPayload,
    documentProblem,
    emptyCounselingForm,
    fileSize,
    forecastFlags,
    todayUtc,
    utcStamp,
} from '@/lib/counseling';
import { postJson } from '@/lib/postJson';
import { toast } from 'vue-sonner';

// The trooper's Developmental Counseling Statements and Letters of Reprimand (#381). The server sends only the records
// this viewer may see, where they stand under the regulations (each record's flags, the no favorable action banner, and
// for the form what a new record would earn by its date), and what the viewer may do (file either type, change each
// record; nothing at all on a trooper who has left the Cav). A DCS or LOR is frozen 72 hours after it is filed
// (`freezesAt`): until then the chain may correct it and the trooper may answer it once (`canAppeal`: an appeal on an
// LOR, a comment or dispute on a DCS). From then on only the Regimental Executive Officer may overturn an LOR
// (`canOverturn`); an overturned one stays listed, muted, with who overturned it and why. This component draws that and posts the forms. Hiding a control is cosmetic: every route
// checks again.
const props = defineProps({
    trooperId: { type: Number, required: true },
    // `Milpac\CounselingHistory` plus `sees` and `canFile`, from TrooperDetailController.
    counseling: { type: Object, required: true },
});

const records = computed(() => props.counseling.records ?? []);
const canFile = computed(() => Object.keys(COUNSELING_TYPES).filter((type) => props.counseling.canFile?.[type]));
const seesBoth = computed(() => props.counseling.sees?.dcs && props.counseling.sees?.lor);
const today = todayUtc();

// Filing: one inline form, opened by the button above the list.
const filing = ref(false);
const fileInput = ref(null);
// `kind` starts unchosen: the leader says what the record is for every time.
// `source` says how the document comes: uploaded as a signed PDF, or made by the portal from its form (`form`).
const fileForm = useForm({ type: 'dcs', kind: '', counseled_on: today, reason: '', document: null, source: 'upload', form: emptyCounselingForm() });
const generating = computed(() => fileForm.source === 'form');
const isLor = computed(() => fileForm.type === 'lor');
const parties = computed(() => props.counseling.formParties ?? null);
const partyRows = computed(() =>
    parties.value
        ? [
              { key: 'trooper', title: 'Trooper', ...parties.value.trooper },
              { key: 'authority', title: isLor.value ? 'Disciplining authority' : 'Counselling authority', ...parties.value.authority },
          ]
        : [],
);
const SECTION_2_FIELDS = [
    { key: 'infraction', label: 'Evidenced infraction and associated details, previous instances and counsellings', rows: 4 },
    { key: 'regulation', label: 'Associated regulation, order or SOP', rows: 2 },
    { key: 'discussion', label: 'Notes of discussion', rows: 4 },
];
// The plan of action and the comments each have three columns, as on the form; the authority's plan is required.
const COLUMN_GROUPS = [
    { key: 'plan', label: 'Agreed plan of action', rows: 3 },
    { key: 'comments', label: 'Any comments', rows: 2 },
];
const COLUMNS = [
    { key: 'authority', label: 'Counselling authority' },
    { key: 'trooper', label: 'Trooper' },
    { key: 'other', label: 'Misc or others' },
];
const formError = (field) => fileForm.errors[`form.${field}`];
const formLevelError = computed(() => (generating.value ? fileForm.errors.document : null));
const hint = computed(() => forecastFlags(props.counseling.forecast, fileForm.type, fileForm.kind, fileForm.counseled_on));

// An error goes as soon as its field is edited, so a fixed field stops showing red before the next Preview or File.
for (const key of ['type', 'kind', 'counseled_on', 'reason', 'document']) {
    watch(
        () => fileForm[key],
        () => fileForm.clearErrors(key),
    );
}
watch(
    () => ({ ...fileForm.form }),
    (now, before) => {
        for (const key of Object.keys(now)) {
            if (now[key] !== before?.[key]) fileForm.clearErrors(`form.${key}`);
        }
    },
);

function openFiling() {
    fileForm.reset();
    fileForm.clearErrors();
    fileForm.type = canFile.value[0];
    fileForm.counseled_on = todayUtc();
    fileForm.form = emptyCounselingForm();
    filing.value = true;
}

function toggleConsequence(value, checked) {
    const picked = fileForm.form.consequences.filter((c) => c !== value);
    fileForm.form.consequences = checked ? [...picked, value] : picked;
    fileForm.clearErrors('form.consequences');
}

// The preview: the server renders the same page the PDF is made from, shown in a sandboxed frame that runs nothing.
const preview = ref({ open: false, html: '', loading: false });

async function openPreview() {
    preview.value.loading = true;
    const result = await postJson(`/troopers/${props.trooperId}/counseling/preview`, {
        type: fileForm.type,
        counseled_on: fileForm.counseled_on,
        form: counselingFormPayload(fileForm.form, fileForm.type),
    }).catch(() => ({ ok: false, status: 0, data: null }));
    preview.value.loading = false;

    if (result.ok && typeof result.data?.html === 'string') {
        preview.value = { open: true, html: result.data.html, loading: false };
        return;
    }
    if (result.status === 422 && result.data?.errors) {
        for (const [field, messages] of Object.entries(result.data.errors)) fileForm.setError(field, [].concat(messages)[0]);
        return;
    }
    toast.error('Could not show the preview', { description: result.data?.message ?? 'Try again in a moment.' });
}

function pickFile(event) {
    fileForm.document = event.target.files?.[0] ?? null;
    fileForm.clearErrors('document');
}

function submitFiling() {
    if (!COUNSELING_KINDS[fileForm.kind]) {
        fileForm.setError('kind', 'Choose what the record is for.');
        return;
    }
    const problem = generating.value ? null : documentProblem(fileForm.document);
    if (problem) {
        fileForm.setError('document', problem);
        return;
    }

    fileForm.transform((data) =>
        generating.value
            ? { type: data.type, kind: data.kind, counseled_on: data.counseled_on, reason: data.reason, source: 'form', form: counselingFormPayload(data.form, data.type) }
            : { type: data.type, kind: data.kind, counseled_on: data.counseled_on, reason: data.reason, source: 'upload', document: data.document },
    );
    fileForm.post(`/troopers/${props.trooperId}/counseling`, {
        forceFormData: !generating.value,
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`${COUNSELING_TYPES[fileForm.type].label} filed`);
            filing.value = false;
            fileForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
        onHttpException: onHttpException('Could not file the record', (message) => toast.error(message)),
    });
}

// Correcting and deleting: one record at a time, inline, each with the reason the audit log keeps.
// A seeded demo record's document has no file behind it: say so instead of starting a download that would fail.
const demoDocumentOpen = ref(false);

function openDocument(event, record) {
    if (record.document?.demo) {
        event.preventDefault();
        demoDocumentOpen.value = true;
    }
}

const editingId = ref(null);
const deletingId = ref(null);
const overturningId = ref(null);
const editForm = useForm({ counseled_on: '', reason: '', audit_reason: '' });
const deleteForm = useForm({ audit_reason: '' });
const overturnForm = useForm({ reason: '' });

// A frozen DCS or LOR, or an overturned LOR, is never corrected or deleted, whatever `canChange` says. An expired DCS (90 days after it
// was counseled, listed for Admins only) is shown read-only.
const changeable = (record) => record.canChange && !record.frozen && !record.overturned && !record.expired;
const busyWith = (record) => [editingId.value, deletingId.value, overturningId.value].includes(record.id);

function startEdit(record) {
    deletingId.value = null;
    overturningId.value = null;
    editingId.value = record.id;
    editForm.clearErrors();
    editForm.counseled_on = record.date;
    editForm.reason = record.reason;
    editForm.audit_reason = '';
}

function startDelete(record) {
    editingId.value = null;
    overturningId.value = null;
    deletingId.value = record.id;
    deleteForm.clearErrors();
    deleteForm.audit_reason = '';
}

function startOverturn(record) {
    editingId.value = null;
    deletingId.value = null;
    overturningId.value = record.id;
    overturnForm.clearErrors();
    overturnForm.reason = '';
}

function cancelChange() {
    editingId.value = null;
    deletingId.value = null;
    overturningId.value = null;
}

function submitEdit(record) {
    editForm.patch(`/counseling/${record.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Record corrected');
            editingId.value = null;
        },
        onHttpException: onHttpException('Could not correct the record', (message) => toast.error(message)),
    });
}

function submitDelete(record) {
    deleteForm.delete(`/counseling/${record.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`${COUNSELING_TYPES[record.type].label} deleted`);
            deletingId.value = null;
        },
        onHttpException: onHttpException('Could not delete the record', (message) => toast.error(message)),
    });
}

function submitOverturn(record) {
    overturnForm.post(`/counseling/${record.id}/overturn`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Letter of Reprimand overturned');
            overturningId.value = null;
        },
        onHttpException: onHttpException('Could not overturn the record', (message) => toast.error(message)),
    });
}

// The trooper's appeal (LOR) or comment or dispute (DCS), in a dialog: one statement, once per record.
const appealing = ref(null);
const appealForm = useForm({ statement: '' });
const appealOpen = computed({
    get: () => appealing.value !== null,
    set: (open) => {
        if (!open) appealing.value = null;
    },
});
const appealWords = (type) => APPEAL_WORDS[type] ?? APPEAL_WORDS.lor;

function startAppeal(record) {
    appealForm.clearErrors();
    appealForm.statement = '';
    appealing.value = record;
}

function submitAppeal() {
    const record = appealing.value;
    if (!record) return;
    if (!appealForm.statement.trim()) {
        appealForm.setError('statement', 'Write your statement.');
        return;
    }
    appealForm.post(`/counseling/${record.id}/appeal`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(appealWords(record.type).filed);
            appealing.value = null;
        },
        onHttpException: onHttpException('Could not file your statement', (message) => toast.error(message)),
    });
}

const emptyText = computed(() => (seesBoth.value ? 'No counseling records on file.' : 'No Letters of Reprimand on file.'));
const typeLabel = (type) => COUNSELING_TYPES[type]?.label ?? type;
const typeShort = (type) => COUNSELING_TYPES[type]?.short ?? type;
const drive = (status) => DRIVE_STATUS[status] ?? { label: status, tone: 'outline' };
</script>

<template>
    <MilpacPanel title="Counseling" data-testid="counseling-panel">
        <template v-if="records.length" #count>
            <Badge variant="secondary" class="rounded-full tabular-nums" :aria-label="`${records.length} ${records.length === 1 ? 'record' : 'records'}`" data-testid="counseling-count">
                {{ records.length }}
            </Badge>
        </template>

        <div v-if="counseling.departed || counseling.warnings?.length || canFile.length" class="flex flex-col gap-3 border-b border-border px-4 py-3">
            <InlineNotice v-if="counseling.departed" :icon="PhLock" role="status" data-testid="counseling-departed">
                This trooper is no longer serving, so their counseling records are read-only. Nobody can file, correct or delete a record. The records on file can still be downloaded.
            </InlineNotice>

            <InlineNotice
                v-for="warning in counseling.warnings"
                :key="warning.key"
                tone="warning"
                :icon="PhWarning"
                role="status"
                :data-testid="`counseling-warning-${warning.key}`"
            >
                {{ warning.message }}
            </InlineNotice>

            <div v-if="canFile.length && !filing">
                <Button variant="outline" size="sm" data-testid="counseling-file-open" @click="openFiling"><PhPlus aria-hidden="true" />File a record</Button>
            </div>

            <form v-if="filing" class="space-y-3" data-testid="counseling-form" @submit.prevent="submitFiling">
                <fieldset class="grid gap-1.5" data-testid="counseling-source">
                    <legend class="mb-1.5 text-sm font-medium">Document</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1.5 text-[13px]">
                        <label v-for="(source, value) in DOCUMENT_SOURCES" :key="value" class="inline-flex items-center gap-1.5">
                            <input v-model="fileForm.source" type="radio" name="counseling-source" :value="value" :data-testid="`counseling-source-${value}`" />
                            {{ source.label }}
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="counseling-type">Type</Label>
                        <NativeSelect id="counseling-type" v-model="fileForm.type" data-testid="counseling-type" :aria-invalid="!!fileForm.errors.type">
                            <option v-for="type in canFile" :key="type" :value="type">{{ typeLabel(type) }} ({{ typeShort(type) }})</option>
                        </NativeSelect>
                        <p v-if="fileForm.errors.type" class="text-xs text-destructive">{{ fileForm.errors.type }}</p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="counseling-date">{{ generating ? 'Date of letter' : 'Date' }}</Label>
                        <Input id="counseling-date" v-model="fileForm.counseled_on" type="date" :max="today" data-testid="counseling-date" :aria-invalid="!!fileForm.errors.counseled_on" />
                        <p v-if="fileForm.errors.counseled_on" class="text-xs text-destructive" data-testid="counseling-error-date">{{ fileForm.errors.counseled_on }}</p>
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="counseling-kind">What it is for</Label>
                    <NativeSelect id="counseling-kind" v-model="fileForm.kind" data-testid="counseling-kind" :aria-invalid="!!fileForm.errors.kind" required @change="fileForm.clearErrors('kind')">
                        <option value="" disabled>Choose one</option>
                        <option v-for="(kind, value) in COUNSELING_KINDS" :key="value" :value="value">{{ kind.label }}</option>
                    </NativeSelect>
                    <p v-if="fileForm.errors.kind" class="text-xs text-destructive" data-testid="counseling-error-kind">{{ fileForm.errors.kind }}</p>
                </div>

                <div v-if="hint.length" class="text-xs text-warning" data-testid="counseling-hint">
                    <p>Filed with this date, the record would be flagged:</p>
                    <ul class="mt-0.5 list-disc pl-4">
                        <li v-for="flag in hint" :key="flag.key" :data-testid="`counseling-hint-${flag.key}`">{{ flag.message }}</li>
                    </ul>
                </div>

                <div class="grid gap-1.5">
                    <Label for="counseling-reason">{{ generating ? 'Summary' : 'Reason' }}</Label>
                    <Textarea id="counseling-reason" v-model="fileForm.reason" rows="3" maxlength="2000" data-testid="counseling-reason" :aria-invalid="!!fileForm.errors.reason" />
                    <p v-if="generating" class="text-xs text-muted-foreground">A short summary, shown in the list of records. The full text goes on the form.</p>
                    <p v-if="fileForm.errors.reason" class="text-xs text-destructive" data-testid="counseling-error-reason">{{ fileForm.errors.reason }}</p>
                </div>

                <div v-if="generating" class="space-y-3" data-testid="counseling-generate">
                    <div v-if="parties" class="overflow-x-auto">
                        <table class="w-full text-left text-[13px]" data-testid="counseling-section-1">
                            <caption class="mb-1 text-left text-xs text-muted-foreground">Section 1, from the roster</caption>
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="pr-3 font-normal">Title</th>
                                    <th scope="col" class="pr-3 font-normal">Rank</th>
                                    <th scope="col" class="pr-3 font-normal">Name</th>
                                    <th scope="col" class="font-normal">Billet</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="party in partyRows" :key="party.key" :data-testid="`counseling-party-${party.key}`">
                                    <th scope="row" class="pr-3 font-normal">{{ party.title }}</th>
                                    <td class="pr-3">{{ party.rank }}</td>
                                    <td class="pr-3" data-testid="counseling-party-name">{{ party.name }}</td>
                                    <td>{{ party.billet }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-for="field in SECTION_2_FIELDS" :key="field.key" class="grid gap-1.5">
                        <Label :for="`counseling-form-${field.key}`">{{ field.label }}</Label>
                        <Textarea
                            :id="`counseling-form-${field.key}`"
                            v-model="fileForm.form[field.key]"
                            :rows="field.rows"
                            :maxlength="FORM_LIMITS[field.key]"
                            required
                            :data-testid="`counseling-form-${field.key}`"
                            :aria-invalid="!!formError(field.key)"
                        />
                        <p v-if="formError(field.key)" class="text-xs text-destructive" :data-testid="`counseling-error-form-${field.key}`">{{ formError(field.key) }}</p>
                    </div>

                    <fieldset v-for="group in COLUMN_GROUPS" :key="group.key" class="grid gap-2">
                        <legend class="mb-1.5 text-sm font-medium">{{ group.label }}</legend>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div v-for="column in COLUMNS" :key="column.key" class="flex min-w-0 flex-col gap-1.5">
                                <Label :for="`counseling-form-${group.key}_${column.key}`" class="truncate text-xs">{{ column.label }}</Label>
                                <Textarea
                                    class="field-sizing-fixed resize-none overflow-y-auto"
                                    :id="`counseling-form-${group.key}_${column.key}`"
                                    v-model="fileForm.form[`${group.key}_${column.key}`]"
                                    :rows="group.rows"
                                    :maxlength="FORM_LIMITS[`${group.key}_${column.key}`]"
                                    :required="group.key === 'plan' && column.key === 'authority'"
                                    :data-testid="`counseling-form-${group.key}_${column.key}`"
                                    :aria-invalid="!!formError(`${group.key}_${column.key}`)"
                                />
                                <p v-if="formError(`${group.key}_${column.key}`)" class="text-xs text-destructive" :data-testid="`counseling-error-form-${group.key}_${column.key}`">
                                    {{ formError(`${group.key}_${column.key}`) }}
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <template v-if="isLor">
                        <fieldset class="grid gap-1.5" data-testid="counseling-form-consequences">
                            <legend class="mb-1.5 text-sm font-medium">Recommended consequence</legend>
                            <label v-for="(consequence, value) in LOR_CONSEQUENCES" :key="value" class="inline-flex items-start gap-1.5 text-[13px]">
                                <input
                                    type="checkbox"
                                    class="mt-0.5"
                                    :checked="fileForm.form.consequences.includes(value)"
                                    :data-testid="`counseling-consequence-${value}`"
                                    @change="toggleConsequence(value, $event.target.checked)"
                                />
                                {{ consequence.label }}
                            </label>
                            <div v-if="fileForm.form.consequences.includes('removal')" class="ml-5 flex flex-col gap-1 text-[13px]" data-testid="counseling-form-removal">
                                <label v-for="(removal, value) in LOR_REMOVALS" :key="value" class="inline-flex items-center gap-1.5">
                                    <input v-model="fileForm.form.removal" type="radio" name="counseling-removal" :value="value" :data-testid="`counseling-removal-${value}`" />
                                    {{ removal.label }}
                                </label>
                            </div>
                            <p v-if="formError('consequences')" class="text-xs text-destructive" data-testid="counseling-error-form-consequences">{{ formError('consequences') }}</p>
                            <p v-if="formError('removal')" class="text-xs text-destructive" data-testid="counseling-error-form-removal">{{ formError('removal') }}</p>
                        </fieldset>

                        <div class="grid gap-1.5">
                            <Label for="counseling-form-special_punishments">Special punishments ordered</Label>
                            <Textarea
                                id="counseling-form-special_punishments"
                                v-model="fileForm.form.special_punishments"
                                rows="2"
                                :maxlength="FORM_LIMITS.special_punishments"
                                data-testid="counseling-form-special_punishments"
                            />
                            <p v-if="formError('special_punishments')" class="text-xs text-destructive">{{ formError('special_punishments') }}</p>
                        </div>

                        <fieldset class="grid gap-1.5" data-testid="counseling-form-appeal">
                            <legend class="mb-1.5 text-sm font-medium">Right to appeal</legend>
                            <p class="text-xs text-muted-foreground">You informed the trooper of their right to appeal one level higher in the chain of command. The trooper:</p>
                            <label v-for="(appeal, value) in LOR_APPEALS" :key="value" class="inline-flex items-center gap-1.5 text-[13px]">
                                <input v-model="fileForm.form.appeal" type="radio" name="counseling-appeal" :value="value" :data-testid="`counseling-appeal-${value}`" />
                                {{ appeal.label }}
                            </label>
                            <p v-if="formError('appeal')" class="text-xs text-destructive" data-testid="counseling-error-form-appeal">{{ formError('appeal') }}</p>
                        </fieldset>
                    </template>
                    <p v-else class="text-xs text-muted-foreground" data-testid="counseling-dcs-fixed">
                        The consequence and appeal parts of a DCS carry the form's fixed text: counselling statements are not punitive and not subject to appeal.
                    </p>

                    <p class="text-xs text-muted-foreground">The portal makes the PDF from this form. It is filed like an uploaded one, and a copy goes to the 7Cav Google Drive when it is set up.</p>
                    <p v-if="formLevelError" class="text-xs text-destructive" data-testid="counseling-error-generated">{{ formLevelError }}</p>
                </div>

                <div v-else class="grid gap-1.5">
                    <Label for="counseling-document">Signed document</Label>
                    <input
                        id="counseling-document"
                        ref="fileInput"
                        type="file"
                        accept="application/pdf,.pdf"
                        class="text-[13px] file:mr-3 file:rounded-md file:border file:border-input file:bg-card file:px-2.5 file:py-1 file:text-[13px]"
                        data-testid="counseling-document"
                        :aria-invalid="!!fileForm.errors.document"
                        aria-describedby="counseling-document-hint"
                        @change="pickFile"
                    />
                    <p id="counseling-document-hint" class="text-xs text-muted-foreground">A PDF of 10 MB or less. A copy goes to the 7Cav Google Drive when it is set up.</p>
                    <p v-if="fileForm.errors.document" class="text-xs text-destructive" data-testid="counseling-error-document">{{ fileForm.errors.document }}</p>
                </div>

                <div class="flex gap-2">
                    <Button type="submit" size="sm" :disabled="fileForm.processing" data-testid="counseling-submit">File record</Button>
                    <Button v-if="generating" type="button" size="sm" variant="outline" :disabled="preview.loading" data-testid="counseling-preview" @click="openPreview">
                        <PhEye aria-hidden="true" />Preview
                    </Button>
                    <Button type="button" size="sm" variant="outline" data-testid="counseling-cancel" @click="filing = false">Cancel</Button>
                </div>
            </form>
        </div>

        <p v-if="!records.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="counseling-empty">{{ emptyText }}</p>

        <ul v-else>
            <li
                v-for="record in records"
                :key="record.id"
                class="flex flex-col gap-1.5 border-b border-border px-4 py-3 text-[13px] last:border-b-0"
                data-testid="counseling-record"
                :data-type="record.type"
                :data-overturned="record.overturned ? 'true' : undefined"
                :data-expired="record.expired ? 'true' : undefined"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="record.type === 'lor' ? 'danger' : 'warning'" :title="typeLabel(record.type)" data-testid="counseling-type-badge">
                        {{ typeShort(record.type) }}<span class="sr-only">: {{ typeLabel(record.type) }}</span>
                    </Badge>
                    <time :datetime="record.date" class="font-mono text-xs" data-testid="counseling-date-text">{{ forumDate(record.date) }}</time>
                    <span v-if="record.kindLabel" class="text-xs text-muted-foreground" data-testid="counseling-kind-text">{{ record.kindLabel }}</span>
                    <Badge v-if="record.frozen && record.freezesAt" variant="secondary" data-testid="counseling-frozen">
                        <PhLock aria-hidden="true" />Frozen <time :datetime="record.freezesAt">{{ utcStamp(record.freezesAt) }}</time>
                    </Badge>
                    <Badge v-if="record.appeal" variant="warning" data-testid="counseling-appeal-filed">{{ appealWords(record.type).filed }}</Badge>
                    <Badge v-if="record.expired" variant="secondary" data-testid="counseling-expired">
                        Expired <time :datetime="record.expired.date">{{ forumDate(record.expired.date) }}</time>
                    </Badge>
                    <Badge :variant="drive(record.driveStatus).tone" class="ml-auto" data-testid="counseling-drive">{{ drive(record.driveStatus).label }}</Badge>
                </div>

                <ul v-if="record.flags?.length" class="flex flex-col gap-0.5 text-xs text-warning" data-testid="counseling-flags">
                    <li v-for="flag in record.flags" :key="flag.key" class="flex items-start gap-1" :data-testid="`counseling-flag-${flag.key}`">
                        <PhWarning class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ flag.message }}
                    </li>
                </ul>
                <p v-if="record.kindAssumed" class="text-xs text-muted-foreground" data-testid="counseling-kind-assumed">
                    Filed before the portal asked what a record is for, so the other conduct rules apply to it.
                </p>

                <p class="whitespace-pre-line" :class="{ 'text-muted-foreground': record.overturned }" data-testid="counseling-reason-text">{{ record.reason }}</p>
                <p class="text-xs text-muted-foreground" data-testid="counseling-issuer">Filed by {{ record.issuer }}<template v-if="record.generated">, made from the form in the portal</template></p>
                <p v-if="record.appeal" class="flex items-start gap-1 text-xs" data-testid="counseling-appeal">
                    <PhChatText class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="whitespace-pre-line"><span class="text-muted-foreground">{{ appealWords(record.type).filed }} <time :datetime="record.appeal.at">{{ utcStamp(record.appeal.at) }}</time>:</span> {{ record.appeal.statement }}</span>
                </p>
                <p v-if="changeable(record) && record.freezesAt" class="text-xs text-muted-foreground" data-testid="counseling-correct-until">
                    Can be corrected until <time :datetime="record.freezesAt">{{ utcStamp(record.freezesAt) }}</time>
                </p>
                <p v-if="record.canAppeal && record.freezesAt" class="text-xs text-muted-foreground" data-testid="counseling-appeal-until">
                    {{ appealWords(record.type).until }} <time :datetime="record.freezesAt">{{ utcStamp(record.freezesAt) }}</time>
                </p>
                <p v-if="record.overturned" class="flex items-start gap-1 text-xs text-muted-foreground" data-testid="counseling-overturned">
                    <PhArrowCounterClockwise class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="whitespace-pre-line">Overturned on {{ forumDate(record.overturned.date) }} by {{ record.overturned.by }}: {{ record.overturned.reason }}</span>
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        :href="`/counseling/${record.id}/document`"
                        class="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                        data-testid="counseling-download"
                        @click="openDocument($event, record)"
                    >
                        <PhFilePdf class="size-3.5" aria-hidden="true" />{{ record.document.name }}
                        <span class="text-muted-foreground">({{ fileSize(record.document.size) }})</span>
                        <PhDownloadSimple class="size-3" aria-hidden="true" /><span class="sr-only">Download</span>
                    </a>
                    <template v-if="changeable(record) && !busyWith(record)">
                        <Button variant="ghost" size="sm" class="ml-auto" data-testid="counseling-edit" @click="startEdit(record)">
                            <PhPencilSimple aria-hidden="true" />Correct
                        </Button>
                        <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" data-testid="counseling-delete" @click="startDelete(record)">
                            <PhTrash aria-hidden="true" />Delete
                        </Button>
                    </template>
                    <Button v-if="record.canAppeal && !record.appeal" variant="ghost" size="sm" class="ml-auto" data-testid="counseling-appeal-open" @click="startAppeal(record)">
                        <PhChatText aria-hidden="true" />{{ appealWords(record.type).action }}
                    </Button>
                    <Button
                        v-if="record.canOverturn && !record.overturned && !busyWith(record)"
                        variant="ghost"
                        size="sm"
                        class="ml-auto"
                        data-testid="counseling-overturn"
                        @click="startOverturn(record)"
                    >
                        <PhArrowCounterClockwise aria-hidden="true" />Overturn
                    </Button>
                </div>

                <form v-if="editingId === record.id" class="mt-1 space-y-2 rounded-md border border-border p-3" data-testid="counseling-edit-form" @submit.prevent="submitEdit(record)">
                    <p class="text-xs text-muted-foreground">The type and the document stay as filed. To change them, delete the record and file it again.</p>
                    <div class="grid gap-1.5">
                        <Label :for="`edit-date-${record.id}`">Date</Label>
                        <Input :id="`edit-date-${record.id}`" v-model="editForm.counseled_on" type="date" :max="today" data-testid="counseling-edit-date" />
                        <p v-if="editForm.errors.counseled_on" class="text-xs text-destructive">{{ editForm.errors.counseled_on }}</p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`edit-reason-${record.id}`">Reason</Label>
                        <Textarea :id="`edit-reason-${record.id}`" v-model="editForm.reason" rows="3" maxlength="2000" data-testid="counseling-edit-reason" />
                        <p v-if="editForm.errors.reason" class="text-xs text-destructive">{{ editForm.errors.reason }}</p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`edit-why-${record.id}`">Reason for the correction</Label>
                        <Input :id="`edit-why-${record.id}`" v-model="editForm.audit_reason" maxlength="500" data-testid="counseling-edit-why" />
                        <p v-if="editForm.errors.audit_reason" class="text-xs text-destructive" data-testid="counseling-edit-why-error">{{ editForm.errors.audit_reason }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="editForm.processing" data-testid="counseling-edit-save">Save correction</Button>
                        <Button type="button" size="sm" variant="outline" @click="cancelChange">Cancel</Button>
                    </div>
                </form>

                <form v-if="deletingId === record.id" class="mt-1 space-y-2 rounded-md border border-destructive/30 p-3" data-testid="counseling-delete-form" @submit.prevent="submitDelete(record)">
                    <p class="text-xs">Delete this {{ typeLabel(record.type) }} and its document from the portal? A copy already on the 7Cav Google Drive stays there.</p>
                    <div class="grid gap-1.5">
                        <Label :for="`delete-why-${record.id}`">Reason for deleting</Label>
                        <Input :id="`delete-why-${record.id}`" v-model="deleteForm.audit_reason" maxlength="500" data-testid="counseling-delete-why" />
                        <p v-if="deleteForm.errors.audit_reason" class="text-xs text-destructive" data-testid="counseling-delete-why-error">{{ deleteForm.errors.audit_reason }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" variant="destructive" :disabled="deleteForm.processing" data-testid="counseling-delete-confirm">Delete record</Button>
                        <Button type="button" size="sm" variant="outline" @click="cancelChange">Cancel</Button>
                    </div>
                </form>

                <form v-if="overturningId === record.id" class="mt-1 space-y-2 rounded-md border border-border p-3" data-testid="counseling-overturn-form" @submit.prevent="submitOverturn(record)">
                    <p class="text-xs">Overturn this Letter of Reprimand? It stays on file, marked overturned with your reason, and no longer counts toward the counseling rules.</p>
                    <div class="grid gap-1.5">
                        <Label :for="`overturn-why-${record.id}`">Reason for overturning</Label>
                        <Input :id="`overturn-why-${record.id}`" v-model="overturnForm.reason" maxlength="500" data-testid="counseling-overturn-why" />
                        <p v-if="overturnForm.errors.reason" class="text-xs text-destructive" data-testid="counseling-overturn-why-error">{{ overturnForm.errors.reason }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="overturnForm.processing" data-testid="counseling-overturn-confirm">Overturn record</Button>
                        <Button type="button" size="sm" variant="outline" @click="cancelChange">Cancel</Button>
                    </div>
                </form>
            </li>
        </ul>

        <Dialog v-model:open="appealOpen">
            <DialogContent class="sm:max-w-lg" data-testid="counseling-appeal-dialog">
                <DialogTitle class="text-base font-semibold">{{ appealing ? appealWords(appealing.type).title : '' }}</DialogTitle>
                <DialogDescription class="text-[13px] text-muted-foreground">
                    You can file one, until <time v-if="appealing" :datetime="appealing.freezesAt">{{ utcStamp(appealing.freezesAt) }}</time>. It is added to
                    the record with the time, and your chain of command sees it there.
                </DialogDescription>
                <form class="space-y-2" data-testid="counseling-appeal-form" @submit.prevent="submitAppeal">
                    <Label for="counseling-appeal-statement">Your statement</Label>
                    <Textarea
                        id="counseling-appeal-statement"
                        v-model="appealForm.statement"
                        rows="5"
                        :maxlength="APPEAL_STATEMENT_MAX"
                        data-testid="counseling-appeal-statement"
                        :aria-invalid="!!appealForm.errors.statement"
                    />
                    <p class="text-xs text-muted-foreground">Up to {{ APPEAL_STATEMENT_MAX }} characters.</p>
                    <p v-if="appealForm.errors.statement" class="text-xs text-destructive" data-testid="counseling-appeal-error">{{ appealForm.errors.statement }}</p>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="appealForm.processing" data-testid="counseling-appeal-submit">
                            {{ appealing ? appealWords(appealing.type).submit : '' }}
                        </Button>
                        <Button type="button" size="sm" variant="outline" @click="appealing = null">Cancel</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="demoDocumentOpen">
            <DialogContent class="sm:max-w-md" data-testid="counseling-demo-dialog">
                <DialogTitle class="text-base font-semibold">Demo file</DialogTitle>
                <DialogDescription class="text-[13px] text-muted-foreground">
                    This file is a demo file and will not be downloaded. You can generate a real one with the form.
                </DialogDescription>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="preview.open">
            <DialogContent class="sm:max-w-3xl" data-testid="counseling-preview-dialog">
                <DialogTitle class="text-base font-semibold">Preview</DialogTitle>
                <DialogDescription class="text-[13px] text-muted-foreground">The document as it will be filed. Close this to change it.</DialogDescription>
                <!-- Sandboxed with no permissions: the page runs no script and reaches nothing. -->
                <iframe
                    :srcdoc="preview.html"
                    sandbox=""
                    title="Preview of the document"
                    class="h-[70vh] w-full rounded-md border border-border bg-white"
                    data-testid="counseling-preview-frame"
                />
            </DialogContent>
        </Dialog>
    </MilpacPanel>
</template>
