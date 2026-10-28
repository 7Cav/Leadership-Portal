<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { PhDownloadSimple, PhFilePdf, PhPencilSimple, PhPlus, PhTrash, PhWarning } from '@phosphor-icons/vue';
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
import { COUNSELING_TYPES, DRIVE_STATUS, documentProblem, fileSize, nextStepHint, todayUtc } from '@/lib/counseling';
import { toast } from 'vue-sonner';

// The trooper's Developmental Counseling Statements and Letters of Reprimand (#381). The server sends only the records
// this viewer may see, the 90-day warnings worked out from them, and what the viewer may do (file either type, change
// each record); this component draws that and posts the forms. Hiding a control is cosmetic: every route checks again.
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
const fileForm = useForm({ type: 'dcs', counseled_on: today, reason: '', document: null });
const hint = computed(() => nextStepHint(fileForm.type, props.counseling.counts));

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
const editForm = useForm({ counseled_on: '', reason: '', audit_reason: '' });
const deleteForm = useForm({ audit_reason: '' });

function startEdit(record) {
    deletingId.value = null;
    editingId.value = record.id;
    editForm.clearErrors();
    editForm.counseled_on = record.date;
    editForm.reason = record.reason;
    editForm.audit_reason = '';
}

function startDelete(record) {
    editingId.value = null;
    deletingId.value = record.id;
    deleteForm.clearErrors();
    deleteForm.audit_reason = '';
}

function cancelChange() {
    editingId.value = null;
    deletingId.value = null;
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

        <div v-if="counseling.warnings?.length || canFile.length" class="flex flex-col gap-3 border-b border-border px-4 py-3">
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

                <p v-if="hint" class="text-xs text-warning" data-testid="counseling-hint">{{ hint }}</p>

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
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="record.type === 'lor' ? 'danger' : 'warning'" :title="typeLabel(record.type)" data-testid="counseling-type-badge">
                        {{ typeShort(record.type) }}<span class="sr-only">: {{ typeLabel(record.type) }}</span>
                    </Badge>
                    <time :datetime="record.date" class="font-mono text-xs" data-testid="counseling-date-text">{{ forumDate(record.date) }}</time>
                    <span class="text-xs text-muted-foreground" data-testid="counseling-window">
                        {{ record.counts ? `Counts until ${forumDate(record.countsUntil)}` : 'Older than 90 days, no longer counts' }}
                    </span>
                    <Badge :variant="drive(record.driveStatus).tone" class="ml-auto" data-testid="counseling-drive">{{ drive(record.driveStatus).label }}</Badge>
                </div>

                <p class="whitespace-pre-line" data-testid="counseling-reason-text">{{ record.reason }}</p>
                <p class="text-xs text-muted-foreground" data-testid="counseling-issuer">Filed by {{ record.issuer }}</p>

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
                    <template v-if="record.canChange && editingId !== record.id && deletingId !== record.id">
                        <Button variant="ghost" size="sm" class="ml-auto" data-testid="counseling-edit" @click="startEdit(record)">
                            <PhPencilSimple aria-hidden="true" />Correct
                        </Button>
                        <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" data-testid="counseling-delete" @click="startDelete(record)">
                            <PhTrash aria-hidden="true" />Delete
                        </Button>
                    </template>
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
            </li>
        </ul>
    </MilpacPanel>
</template>
