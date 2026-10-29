<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhClockCounterClockwise, PhPlus, PhUploadSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/components/EmptyState.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';
import { postJson } from '@/lib/postJson';

// The changelog: what changed in the portal, newest first, for everyone. A changelog editor also adds, edits, deletes
// and bulk imports entries here. Bodies arrive as HTML the server rendered from Markdown with raw HTML stripped.
const props = defineProps({
    // `[{ id, date, title, html, body }]`, newest first; `body` (the Markdown) only for an editor.
    entries: { type: Array, required: true },
    isEditor: { type: Boolean, required: true },
});

const today = () => new Date().toISOString().slice(0, 10);

// The add and edit form. The outdated bar's "Add entry" opens it with `?add=1`.
const formOpen = ref(props.isEditor && typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('add'));
const editingId = ref(null);
const form = useForm({ date: today(), title: '', body: '' });

function openAdd() {
    importOpen.value = false;
    editingId.value = null;
    form.clearErrors();
    form.date = today();
    form.title = '';
    form.body = '';
    formOpen.value = true;
}

function edit(entry) {
    importOpen.value = false;
    editingId.value = entry.id;
    form.clearErrors();
    form.date = entry.date;
    form.title = entry.title;
    form.body = entry.body ?? '';
    formOpen.value = true;
}

function closeForm() {
    formOpen.value = false;
    editingId.value = null;
    form.clearErrors();
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => closeForm(),
        onHttpException: onHttpException(editingId.value ? 'The entry was not saved' : 'The entry was not added', (message) => toast.error(message)),
    };

    if (editingId.value) form.patch(`/changelog/${editingId.value}`, options);
    else form.post('/changelog', options);
}

// Delete asks for the reason the audit log keeps.
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
    closeForm();
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

        <Card v-if="isEditor && formOpen" class="gap-3 p-4" data-testid="changelog-form">
            <h2 class="text-[15px] font-semibold">{{ editingId ? 'Edit entry' : 'Add entry' }}</h2>
            <form class="space-y-3" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <div class="space-y-1.5">
                        <Label for="changelog-date">Date</Label>
                        <Input id="changelog-date" v-model="form.date" type="date" data-testid="changelog-date" :aria-invalid="!!form.errors.date" />
                        <p v-if="form.errors.date" class="text-xs text-destructive">{{ form.errors.date }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="changelog-title">Title</Label>
                        <Input id="changelog-title" v-model="form.title" maxlength="200" data-testid="changelog-title" :aria-invalid="!!form.errors.title" />
                        <p v-if="form.errors.title" class="text-xs text-destructive">{{ form.errors.title }}</p>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <Label for="changelog-body">Text (Markdown)</Label>
                    <Textarea id="changelog-body" v-model="form.body" rows="8" data-testid="changelog-body" :aria-invalid="!!form.errors.body" />
                    <p v-if="form.errors.body" class="text-xs text-destructive">{{ form.errors.body }}</p>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" size="sm" :disabled="form.processing" data-testid="changelog-save">{{ editingId ? 'Save changes' : 'Add entry' }}</Button>
                    <Button type="button" size="sm" variant="ghost" @click="closeForm">Cancel</Button>
                </div>
            </form>
        </Card>

        <Card v-if="isEditor && importOpen" class="gap-3 p-4" data-testid="changelog-import-panel">
            <h2 class="text-[15px] font-semibold">Bulk import</h2>
            <p class="text-[13px] text-muted-foreground">Start each entry with a heading like "## 2026-10-29 - Title". The text up to the next heading is the entry.</p>
            <Textarea v-model="markdown" rows="10" aria-label="Markdown to import" data-testid="changelog-import-markdown" @input="preview = null" />
            <label class="flex items-center gap-2 text-[13px]">
                <Checkbox v-model="skipDuplicates" data-testid="changelog-import-skip" />
                Skip entries whose date and title already exist
            </label>
            <div class="flex gap-2">
                <Button size="sm" variant="outline" :disabled="previewing || markdown.trim() === ''" data-testid="changelog-import-preview" @click="runPreview">Preview</Button>
                <Button size="sm" :disabled="!canImport" data-testid="changelog-import-save" @click="runImport">
                    {{ toSave === 1 ? 'Import 1 entry' : `Import ${toSave} entries` }}
                </Button>
            </div>
            <p v-if="importError" role="alert" class="text-xs text-destructive" data-testid="changelog-import-error">{{ importError }}</p>

            <template v-if="preview">
                <ul v-if="preview.errors.length" class="space-y-1 text-xs text-destructive" role="alert" data-testid="changelog-import-errors">
                    <li v-for="error in preview.errors" :key="error.line + error.message">{{ error.message }}</li>
                </ul>
                <ul class="divide-y divide-border rounded-md border border-border text-[13px]" data-testid="changelog-import-entries">
                    <li v-for="entry in preview.entries" :key="entry.line" class="flex items-center gap-2 px-3 py-2" :data-testid="`preview-entry-${entry.line}`">
                        <span class="tabular-nums text-muted-foreground">{{ entry.date }}</span>
                        <span class="min-w-0 flex-1 truncate" :class="{ 'text-muted-foreground line-through': skipped(entry) }">{{ entry.title }}</span>
                        <Badge v-if="skipped(entry)" variant="outline" :data-testid="`preview-skipped-${entry.line}`">Skipped, already exists</Badge>
                    </li>
                </ul>
            </template>
        </Card>

        <ol v-if="entries.length" class="space-y-4" data-testid="changelog-entries">
            <li v-for="entry in entries" :key="entry.id" :data-testid="`changelog-entry-${entry.id}`">
                <Card class="gap-2 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-[15px] font-semibold">{{ entry.title }}</h2>
                        <time class="text-xs text-muted-foreground tabular-nums" :datetime="entry.date">{{ entry.date }}</time>
                    </div>
                    <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped -->
                    <div class="docs-prose text-[13px]" data-testid="changelog-body-html" v-html="entry.html" />
                    <div v-if="isEditor" class="flex gap-1">
                        <Button variant="ghost" size="sm" :data-testid="`changelog-edit-${entry.id}`" @click="edit(entry)">Edit</Button>
                        <Button variant="ghost" size="sm" class="text-destructive" :data-testid="`changelog-delete-${entry.id}`" @click="requestDelete(entry)">Delete</Button>
                    </div>
                </Card>
            </li>
        </ol>
        <EmptyState v-else :icon="PhClockCounterClockwise" title="No entries yet" class="py-10" data-testid="changelog-empty">
            Changes to the portal are listed here once the changelog editors write them.
        </EmptyState>

        <ReasonDialog
            :open="deleting !== null"
            :title="deleting ? `Delete ${deleting.title}?` : ''"
            description="It is removed from the changelog for everyone."
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
