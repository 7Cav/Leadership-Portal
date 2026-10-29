<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { PhArrowCounterClockwise, PhDownloadSimple, PhFilePdf, PhLock, PhPencilSimple, PhPlus, PhTrash, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import MilpacPanel from '@/components/milpac/MilpacPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { COUNSELING_KINDS, COUNSELING_TYPES, DRIVE_STATUS, documentProblem, fileSize, forecastFlags, todayUtc } from '@/lib/counseling';
import { toast } from 'vue-sonner';

// The trooper's Developmental Counseling Statements and Letters of Reprimand (#381). The server sends only the records
// this viewer may see, where they stand under the regulations (each record's flags, the no favorable action banner, and
// for the form what a new record would earn by its date), and what the viewer may do (file either type, change each
// record; nothing at all on a trooper who has left the Cav). An LOR is frozen 72 hours after it is filed: from then on
// only the Regimental Executive Officer may overturn it (`canOverturn`), and an overturned one stays listed, muted, with
// who overturned it and why. This component draws that and posts the forms. Hiding a control is cosmetic: every route
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
const fileForm = useForm({ type: 'dcs', kind: '', counseled_on: today, reason: '', document: null });
const hint = computed(() => forecastFlags(props.counseling.forecast, fileForm.type, fileForm.kind, fileForm.counseled_on));

function openFiling() {
    fileForm.reset();
    fileForm.clearErrors();
    fileForm.type = canFile.value[0];
    fileForm.counseled_on = todayUtc();
    filing.value = true;
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
    const problem = documentProblem(fileForm.document);
    if (problem) {
        fileForm.setError('document', problem);
        return;
    }

    fileForm.post(`/troopers/${props.trooperId}/counseling`, {
        forceFormData: true,
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
const editingId = ref(null);
const deletingId = ref(null);
const overturningId = ref(null);
const editForm = useForm({ counseled_on: '', reason: '', audit_reason: '' });
const deleteForm = useForm({ audit_reason: '' });
const overturnForm = useForm({ reason: '' });

// A frozen or overturned LOR is never corrected or deleted, whatever `canChange` says. An expired DCS (90 days after it
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
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="counseling-type">Type</Label>
                        <NativeSelect id="counseling-type" v-model="fileForm.type" data-testid="counseling-type" :aria-invalid="!!fileForm.errors.type">
                            <option v-for="type in canFile" :key="type" :value="type">{{ typeLabel(type) }} ({{ typeShort(type) }})</option>
                        </NativeSelect>
                        <p v-if="fileForm.errors.type" class="text-xs text-destructive">{{ fileForm.errors.type }}</p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="counseling-date">Date</Label>
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
                    <Label for="counseling-reason">Reason</Label>
                    <Textarea id="counseling-reason" v-model="fileForm.reason" rows="3" maxlength="2000" data-testid="counseling-reason" :aria-invalid="!!fileForm.errors.reason" />
                    <p v-if="fileForm.errors.reason" class="text-xs text-destructive" data-testid="counseling-error-reason">{{ fileForm.errors.reason }}</p>
                </div>

                <div class="grid gap-1.5">
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
                <p class="text-xs text-muted-foreground" data-testid="counseling-issuer">Filed by {{ record.issuer }}</p>
                <p v-if="record.overturned" class="flex items-start gap-1 text-xs text-muted-foreground" data-testid="counseling-overturned">
                    <PhArrowCounterClockwise class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="whitespace-pre-line">Overturned on {{ forumDate(record.overturned.date) }} by {{ record.overturned.by }}: {{ record.overturned.reason }}</span>
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        :href="`/counseling/${record.id}/document`"
                        class="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                        data-testid="counseling-download"
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
    </MilpacPanel>
</template>
