<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhCaretRight, PhClockCounterClockwise, PhPencilSimple, PhPlus, PhTrash, PhUploadSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/components/EmptyState.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { toast } from 'vue-sonner';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { postJson } from '@/lib/postJson';

// The changelog: what changed in the portal, grouped by (Zulu) date, newest first, for everyone. Each entry is one line;
// one with additional information has a chevron that opens it. A changelog editor also adds a date's batch of entries,
// edits or deletes each entry, and bulk imports. Details arrive as HTML the server rendered from Markdown with raw HTML
// stripped.
const props = defineProps({
    // `[{ date, entries: [{ id, body, detailsHtml, details }] }]`, newest date first; `details` (the Markdown) only for an editor.
    days: { type: Array, required: true },
    isEditor: { type: Boolean, required: true },
});

const MAX_BODY = 300;
const MAX_DETAILS = 2000;
const MAX_BATCH = 50;

// Today in Zulu: the dates are UTC days.
const today = () => new Date().toISOString().slice(0, 10);

// Which entries have their details open. All start collapsed.
const open = ref(new Set());
function toggle(id) {
    const next = new Set(open.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    open.value = next;
}

// The Add dialog: one date and its rows. The outdated bar's "Add entry" opens it with `?add=1`.
let nextKey = 1;
const blankRow = () => ({ key: nextKey++, body: '', hasDetails: false, details: '' });
const addOpen = ref(props.isEditor && typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('add'));
const addForm = useForm({ date: today(), rows: [blankRow()] });
const addCount = ref(3);
const rowInputs = new Map();

function openAdd() {
    importOpen.value = false;
    addForm.clearErrors();
    addForm.date = today();
    addForm.rows = [blankRow()];
    addOpen.value = true;
}

async function focusRow(key) {
    await nextTick();
    rowInputs.get(key)?.focus();
}

function insertRowAfter(index) {
    if (addForm.rows.length >= MAX_BATCH) return;
    const row = blankRow();
    addForm.rows.splice(index + 1, 0, row);
    focusRow(row.key);
}

function appendRows() {
    const room = MAX_BATCH - addForm.rows.length;
    const count = Math.max(0, Math.min(Number(addCount.value) || 0, room));
    if (count === 0) return;
    const rows = Array.from({ length: count }, blankRow);
    addForm.rows.push(...rows);
    focusRow(rows[0].key);
}

function removeRow(index) {
    addForm.rows.splice(index, 1);
    if (addForm.rows.length === 0) addForm.rows.push(blankRow());
}

// Rows with text are sent; blank rows are left out. `sent[i]` is the row index of the i-th sent entry, to place errors.
const sent = computed(() => addForm.rows.map((row, index) => (row.body.trim() !== '' ? index : null)).filter((index) => index !== null));

function rowError(index, field) {
    const position = sent.value.indexOf(index);
    return position === -1 ? null : addForm.errors[`entries.${position}.${field}`];
}

const addError = computed(() => addForm.errors.entries ?? addForm.errors.date ?? null);

function submitAdd() {
    if (sent.value.length === 0) {
        addForm.setError('entries', 'Write at least one change.');
        return;
    }
    addForm
        .transform((data) => ({
            date: data.date,
            entries: sent.value.map((index) => {
                const row = data.rows[index];
                return { body: row.body, details: row.hasDetails && row.details.trim() !== '' ? row.details : null };
            }),
        }))
        .post('/changelog', {
            preserveScroll: true,
            onSuccess: () => (addOpen.value = false),
            onHttpException: onHttpException('The entries were not added', (message) => toast.error(message)),
        });
}

// Edit one entry.
const editing = ref(null);
const editForm = useForm({ date: '', body: '', hasDetails: false, details: '' });

function edit(date, entry) {
    importOpen.value = false;
    editForm.clearErrors();
    editForm.date = date;
    editForm.body = entry.body;
    editForm.details = entry.details ?? '';
    editForm.hasDetails = (entry.details ?? '') !== '';
    editing.value = entry;
}

function submitEdit() {
    editForm
        .transform((data) => ({ date: data.date, body: data.body, details: data.hasDetails && data.details.trim() !== '' ? data.details : null }))
        .patch(`/changelog/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
            onHttpException: onHttpException('The entry was not saved', (message) => toast.error(message)),
        });
}

// Delete asks to confirm, with the reason the audit log keeps.
const deleting = ref(null);
const deleteError = ref(null);
const deleteProcessing = ref(false);

function requestDelete(entry) {
    deleteError.value = null;
    deleting.value = entry;
}

function confirmDelete(reason) {
    const entry = deleting.value;
    if (!entry || deleteProcessing.value) return;

    router.delete(`/changelog/${entry.id}`, {
        data: { reason },
        preserveScroll: true,
        onStart: () => (deleteProcessing.value = true),
        onSuccess: () => (deleting.value = null),
        onError: (errors) => (deleteError.value = errors.reason ?? Object.values(errors)[0] ?? 'The entry was not deleted. Try again.'),
        onHttpException: onHttpException('The entry was not deleted', (message) => (deleteError.value = message)),
        onFinish: () => (deleteProcessing.value = false),
    });
}

// Bulk import: paste, preview, then save. The preview comes from the server, which saves the same parse.
const importOpen = ref(false);
const markdown = ref('');
const skipDuplicates = ref(true);
const preview = ref(null);
const previewing = ref(false);
const importError = ref(null);
const importing = ref(false);

function openImport() {
    importOpen.value = true;
    preview.value = null;
    importError.value = null;
}

async function runPreview() {
    previewing.value = true;
    importError.value = null;
    try {
        const { ok, data } = await postJson('/changelog/import/preview', { markdown: markdown.value });
        if (ok) preview.value = data;
        else importError.value = data?.message ?? 'The preview could not be made. Try again.';
    } catch {
        importError.value = 'The preview could not be made. Check your connection and try again.';
    } finally {
        previewing.value = false;
    }
}

const skipped = (entry) => skipDuplicates.value && entry.duplicate;
const toSave = computed(() => (preview.value ? preview.value.entries.filter((entry) => !skipped(entry)).length : 0));
const canImport = computed(() => preview.value !== null && preview.value.errors.length === 0 && toSave.value > 0 && !importing.value);

// The preview grouped by date in paste order, like the page.
const previewDays = computed(() => {
    const days = [];
    for (const entry of preview.value?.entries ?? []) {
        const last = days[days.length - 1];
        if (last && last.date === entry.date) last.entries.push(entry);
        else days.push({ date: entry.date, entries: [entry] });
    }
    return days;
});

function runImport() {
    router.post(
        '/changelog/import',
        { markdown: markdown.value, skip_duplicates: skipDuplicates.value },
        {
            preserveScroll: true,
            onStart: () => (importing.value = true),
            onSuccess: () => {
                importOpen.value = false;
                markdown.value = '';
                preview.value = null;
            },
            onError: (errors) => (importError.value = errors.markdown ?? Object.values(errors)[0] ?? 'Nothing was imported. Try again.'),
            onHttpException: onHttpException('Nothing was imported', (message) => (importError.value = message)),
            onFinish: () => (importing.value = false),
        },
    );
}
</script>

<template>
    <Head title="Changelog · 7Cav Leadership Portal" />

    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 class="text-xl font-semibold tracking-tight">Changelog</h1>
            <div v-if="isEditor" class="flex flex-wrap gap-2">
                <Button size="sm" data-testid="changelog-add" @click="openAdd"><PhPlus aria-hidden="true" />Add</Button>
                <Button size="sm" variant="outline" data-testid="changelog-import" @click="openImport"><PhUploadSimple aria-hidden="true" />Bulk import</Button>
            </div>
        </div>

        <Card v-if="isEditor && importOpen" class="gap-3 p-4" data-testid="changelog-import-panel">
            <h2 class="text-[15px] font-semibold">Bulk import</h2>
            <p class="text-[13px] text-muted-foreground">
                Start each date with a heading like "## 2026-10-29". Put each change under it on a line starting with "- ". Indent lines under a change
                to add additional information.
            </p>
            <Textarea v-model="markdown" rows="10" class="font-mono text-xs" aria-label="Markdown to import" data-testid="changelog-import-markdown" @input="preview = null" />
            <label class="flex items-center gap-2 text-[13px]">
                <Checkbox v-model="skipDuplicates" data-testid="changelog-import-skip" />
                Skip changes that already exist on the same date
            </label>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" :disabled="previewing || markdown.trim() === ''" data-testid="changelog-import-preview" @click="runPreview">Preview</Button>
                <Button size="sm" :disabled="!canImport" data-testid="changelog-import-save" @click="runImport">
                    {{ toSave === 1 ? 'Import 1 entry' : `Import ${toSave} entries` }}
                </Button>
                <Button size="sm" variant="ghost" @click="importOpen = false">Cancel</Button>
            </div>
            <p v-if="importError" role="alert" class="text-xs text-destructive" data-testid="changelog-import-error">{{ importError }}</p>

            <template v-if="preview">
                <ul v-if="preview.errors.length" class="space-y-1 text-xs text-destructive" role="alert" data-testid="changelog-import-errors">
                    <li v-for="error in preview.errors" :key="error.line + error.message">{{ error.message }}</li>
                </ul>
                <div v-if="previewDays.length" class="space-y-3 rounded-md border border-border p-3 text-[13px]" data-testid="changelog-import-entries">
                    <section v-for="day in previewDays" :key="day.date">
                        <h3 class="text-xs font-semibold text-muted-foreground tabular-nums">{{ forumDate(day.date) }}</h3>
                        <ul class="mt-1 space-y-0.5">
                            <li v-for="entry in day.entries" :key="entry.line" class="flex items-baseline gap-2" :data-testid="`preview-entry-${entry.line}`">
                                <span class="size-1.5 shrink-0 translate-y-[-2px] rounded-full bg-muted-foreground/60" aria-hidden="true" />
                                <span class="min-w-0 flex-1" :class="{ 'text-muted-foreground line-through': skipped(entry) }">
                                    {{ entry.body }}
                                    <span v-if="entry.details" class="text-muted-foreground" :data-testid="`preview-details-${entry.line}`">(with additional information)</span>
                                </span>
                                <Badge v-if="skipped(entry)" variant="outline" :data-testid="`preview-skipped-${entry.line}`">Skipped, already exists</Badge>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </Card>

        <div v-if="days.length" class="space-y-5" data-testid="changelog-days">
            <section v-for="day in days" :key="day.date" :data-testid="`changelog-day-${day.date}`">
                <h2 class="border-b border-border pb-1 text-[13px] font-semibold tabular-nums">
                    <time :datetime="day.date">{{ forumDate(day.date) }}</time>
                </h2>
                <ul class="mt-1.5 space-y-0.5">
                    <li v-for="entry in day.entries" :key="entry.id" class="group" :data-testid="`changelog-entry-${entry.id}`">
                        <div class="flex items-start gap-2 rounded-sm py-0.5 text-[13px] leading-5">
                            <button
                                v-if="entry.detailsHtml"
                                type="button"
                                class="mt-0.5 -ml-0.5 flex size-4 shrink-0 items-center justify-center rounded-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-expanded="open.has(entry.id)"
                                :aria-controls="`changelog-details-${entry.id}`"
                                :aria-label="open.has(entry.id) ? 'Hide additional information' : 'Show additional information'"
                                :data-testid="`changelog-toggle-${entry.id}`"
                                @click="toggle(entry.id)"
                            >
                                <PhCaretRight class="size-3.5 transition-transform" :class="{ 'rotate-90': open.has(entry.id) }" weight="bold" />
                            </button>
                            <span v-else class="flex size-4 shrink-0 items-center justify-center" aria-hidden="true">
                                <span class="size-1.5 rounded-full bg-muted-foreground/60" />
                            </span>
                            <span class="min-w-0 flex-1 break-words" data-testid="changelog-entry-body">{{ entry.body }}</span>
                            <span v-if="isEditor" class="flex shrink-0 gap-0.5 sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100">
                                <Button variant="ghost" size="icon" class="size-6" aria-label="Edit entry" :data-testid="`changelog-edit-${entry.id}`" @click="edit(day.date, entry)">
                                    <PhPencilSimple class="size-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-6 text-destructive"
                                    aria-label="Delete entry"
                                    :data-testid="`changelog-delete-${entry.id}`"
                                    @click="requestDelete(entry)"
                                >
                                    <PhTrash class="size-3.5" />
                                </Button>
                            </span>
                        </div>
                        <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped -->
                        <div
                            v-if="entry.detailsHtml && open.has(entry.id)"
                            :id="`changelog-details-${entry.id}`"
                            class="docs-prose mb-1.5 ml-6 border-l-2 border-border pl-3 text-[13px] text-muted-foreground"
                            :data-testid="`changelog-details-${entry.id}`"
                            v-html="entry.detailsHtml"
                        />
                    </li>
                </ul>
            </section>
        </div>
        <EmptyState v-else :icon="PhClockCounterClockwise" title="No entries yet" class="py-10" data-testid="changelog-empty">
            Changes to the portal are listed here once the changelog editors write them.
        </EmptyState>

        <Dialog v-if="isEditor" :open="addOpen" @update:open="(value) => !addForm.processing && (addOpen = value)">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl" data-testid="changelog-add-dialog">
                <DialogHeader>
                    <DialogTitle>Add entries</DialogTitle>
                    <DialogDescription>One change per row. Press Enter in a row to add one below it.</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submitAdd">
                    <div class="space-y-1.5">
                        <Label for="changelog-add-date">Date (Zulu)</Label>
                        <Input id="changelog-add-date" v-model="addForm.date" type="date" class="w-44" data-testid="changelog-add-date" :aria-invalid="!!addForm.errors.date" />
                    </div>

                    <ol class="space-y-2" data-testid="changelog-rows">
                        <li v-for="(row, index) in addForm.rows" :key="row.key" class="space-y-1.5" :data-testid="`changelog-row-${index}`">
                            <div class="flex items-center gap-1.5">
                                <Input
                                    :ref="(el) => (el ? rowInputs.set(row.key, el.$el ?? el) : rowInputs.delete(row.key))"
                                    v-model="row.body"
                                    :maxlength="MAX_BODY"
                                    :aria-label="`Change ${index + 1}`"
                                    placeholder="What changed"
                                    class="flex-1"
                                    :aria-invalid="!!rowError(index, 'body')"
                                    :data-testid="`changelog-row-body-${index}`"
                                    @keydown.enter.prevent="insertRowAfter(index)"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    aria-label="Add a row below"
                                    :disabled="addForm.rows.length >= MAX_BATCH"
                                    :data-testid="`changelog-row-insert-${index}`"
                                    @click="insertRowAfter(index)"
                                >
                                    <PhPlus class="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 text-destructive"
                                    aria-label="Remove row"
                                    :data-testid="`changelog-row-remove-${index}`"
                                    @click="removeRow(index)"
                                >
                                    <PhTrash class="size-4" />
                                </Button>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-muted-foreground">
                                <Checkbox v-model="row.hasDetails" :data-testid="`changelog-row-has-details-${index}`" />
                                Add additional information
                            </label>
                            <Textarea
                                v-if="row.hasDetails"
                                v-model="row.details"
                                rows="3"
                                :maxlength="MAX_DETAILS"
                                :aria-label="`Additional information for change ${index + 1} (Markdown)`"
                                placeholder="Markdown"
                                :data-testid="`changelog-row-details-${index}`"
                            />
                            <p v-if="rowError(index, 'body') || rowError(index, 'details')" class="text-xs text-destructive">
                                {{ rowError(index, 'body') ?? rowError(index, 'details') }}
                            </p>
                        </li>
                    </ol>

                    <div class="flex items-center gap-1.5">
                        <Input v-model.number="addCount" type="number" min="1" :max="MAX_BATCH" class="h-8 w-16" aria-label="Rows to add" data-testid="changelog-add-count" />
                        <Button type="button" size="sm" variant="outline" :disabled="addForm.rows.length >= MAX_BATCH" data-testid="changelog-add-rows" @click="appendRows">
                            Add {{ addCount }} {{ addCount === 1 ? 'row' : 'rows' }}
                        </Button>
                    </div>

                    <p v-if="addError" role="alert" class="text-xs text-destructive" data-testid="changelog-add-error">{{ addError }}</p>

                    <DialogFooter>
                        <Button type="button" variant="ghost" size="sm" :disabled="addForm.processing" @click="addOpen = false">Cancel</Button>
                        <Button type="submit" size="sm" :disabled="addForm.processing" data-testid="changelog-add-save">
                            {{ sent.length === 1 ? 'Add 1 entry' : `Add ${sent.length} entries` }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-if="isEditor" :open="editing !== null" @update:open="(value) => !value && !editForm.processing && (editing = null)">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl" data-testid="changelog-edit-dialog">
                <DialogHeader>
                    <DialogTitle>Edit entry</DialogTitle>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submitEdit">
                    <div class="space-y-1.5">
                        <Label for="changelog-edit-date">Date (Zulu)</Label>
                        <Input id="changelog-edit-date" v-model="editForm.date" type="date" class="w-44" data-testid="changelog-edit-date" :aria-invalid="!!editForm.errors.date" />
                        <p v-if="editForm.errors.date" class="text-xs text-destructive">{{ editForm.errors.date }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="changelog-edit-body">Change</Label>
                        <Input id="changelog-edit-body" v-model="editForm.body" :maxlength="MAX_BODY" data-testid="changelog-edit-body" :aria-invalid="!!editForm.errors.body" />
                        <p v-if="editForm.errors.body" class="text-xs text-destructive">{{ editForm.errors.body }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-muted-foreground">
                        <Checkbox v-model="editForm.hasDetails" data-testid="changelog-edit-has-details" />
                        Add additional information
                    </label>
                    <Textarea
                        v-if="editForm.hasDetails"
                        v-model="editForm.details"
                        rows="5"
                        :maxlength="MAX_DETAILS"
                        aria-label="Additional information (Markdown)"
                        placeholder="Markdown"
                        data-testid="changelog-edit-details"
                    />
                    <p v-if="editForm.errors.details" class="text-xs text-destructive">{{ editForm.errors.details }}</p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" size="sm" :disabled="editForm.processing" @click="editing = null">Cancel</Button>
                        <Button type="submit" size="sm" :disabled="editForm.processing" data-testid="changelog-edit-save">Save changes</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ReasonDialog
            :open="deleting !== null"
            :title="deleting ? 'Delete this entry?' : ''"
            :description="deleting ? `“${deleting.body}” is removed from the changelog for everyone.` : null"
            confirm-label="Delete entry"
            pending-label="Deleting"
            destructive
            :processing="deleteProcessing"
            :error="deleteError"
            testid="changelog-delete-dialog"
            @update:open="(open) => !open && !deleteProcessing && (deleting = null)"
            @confirm="confirmDelete"
        />
    </div>
</template>
