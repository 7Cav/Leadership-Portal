<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhCaretRight, PhClockCounterClockwise, PhGear, PhPencilSimple, PhPlus, PhTrash, PhUploadSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import EmptyState from '@/components/EmptyState.vue';
import ReasonDialog from '@/components/ReasonDialog.vue';
import { toast } from 'vue-sonner';
import { forumDate, forumDateTime } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { postJson } from '@/lib/postJson';

// The changelog: what changed in the portal, grouped by (Zulu) date, newest first, for everyone. Two columns: "Major
// changes" lists only the entries flagged major; "Full changelog" lists every entry and starts collapsed unless this
// viewer opened it before (remembered in this browser only). On a phone the two stack, major first. Each entry is one line;
// one with additional information has a chevron that opens it. A changelog editor also adds a date's batch of entries,
// edits or deletes each entry, and bulk imports. Details arrive as HTML the server rendered from Markdown with raw HTML
// stripped.
//
// Technical entries (behind-the-scenes and security work) never show under Major changes. In the full changelog they
// show, marked, only after the viewer ticks "Show technical and security items" in the filter bar (remembered in this browser), and an
// editor always sees them. The server sends them only while an Admin has made them available, or to an editor.
const props = defineProps({
    // `[{ date, entries: [{ id, body, major, technical, detailsHtml, details }] }]`, newest date first; `details` (the Markdown) only for an editor.
    days: { type: Array, required: true },
    isEditor: { type: Boolean, required: true },
    // Whether this viewer gets the toggle for technical entries: an Admin made them available, and they are not an editor.
    technicalToggle: { type: Boolean, default: false },
    // The Admin setting `{ enabled }`, only for an Admin who may change it.
    technicalSetting: { type: Object, default: null },
    // While a delete all can be restored, `{ count, deletedAt, restoreUntil }` (UTC), only for an editor.
    pendingRestore: { type: Object, default: null },
});

const MAX_BODY = 300;
const MAX_DETAILS = 2000;
const MAX_BATCH = 50;

// Today in Zulu: the dates are UTC days.
const today = () => new Date().toISOString().slice(0, 10);

// Per-viewer choices remembered in localStorage. Storage can be missing or throw (private windows, blocked site data);
// the page then just starts with the choice off.
function readFlag(key) {
    try {
        return typeof window !== 'undefined' && window.localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}
function rememberFlag(key, value) {
    try {
        window.localStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Not remembered; nothing else depends on it.
    }
}

// Whether technical entries show in the full changelog: always for an editor, otherwise when the viewer asked for them.
const showTechnical = ref(readFlag('changelog.showTechnical'));
watch(showTechnical, (value) => rememberFlag('changelog.showTechnical', value));
const technicalShown = computed(() => props.isEditor || (props.technicalToggle && showTechnical.value));

const keepDays = (keep) => props.days.map((day) => ({ date: day.date, entries: day.entries.filter(keep) })).filter((day) => day.entries.length > 0);

// The patch-notes tag an entry's text starts with ("Fixed: ..."), or null.
const KINDS = ['Added', 'Changed', 'Fixed', 'Removed', 'Security'];
const kindOf = (entry) => KINDS.find((kind) => (entry.body ?? '').toLowerCase().startsWith(`${kind.toLowerCase()}:`)) ?? null;
// The Add and Edit forms pick the tag from a list and keep the text apart. A tag the editor typed anyway wins, so it is
// never doubled; an existing entry's leading tag goes back into the picker.
function splitTag(body) {
    const kind = kindOf({ body });
    return kind ? { tag: kind, text: body.slice(kind.length + 1).trimStart() } : { tag: 'Added', text: body };
}
function withTag(tag, text) {
    const typed = splitTag(text.trim());
    return `${kindOf({ body: text.trim() }) ? typed.tag : tag}: ${typed.text}`;
}
const textRoom = (tag) => MAX_BODY - tag.length - 2;

// The filter bar: one chip at a time, remembered in this browser. Technical only while technical items show.
const FILTERS = ['all', 'major', ...KINDS.map((kind) => kind.toLowerCase()), 'technical'];
function readFilter() {
    try {
        const value = typeof window !== 'undefined' ? window.localStorage.getItem('changelog.filter') : null;
        return FILTERS.includes(value) ? value : 'all';
    } catch {
        return 'all';
    }
}
const filter = ref(readFilter());
watch(filter, (value) => {
    try {
        window.localStorage.setItem('changelog.filter', value);
    } catch {
        // Not remembered.
    }
});
const filterChips = computed(() => [
    { key: 'all', label: 'All' },
    { key: 'major', label: 'Major' },
    ...KINDS.map((kind) => ({ key: kind.toLowerCase(), label: kind })),
    ...(technicalShown.value ? [{ key: 'technical', label: 'Technical' }] : []),
]);
// A remembered Technical filter falls back to All once technical items are hidden.
const activeFilter = computed(() => (filter.value === 'technical' && !technicalShown.value ? 'all' : filter.value));
function matches(entry) {
    const key = activeFilter.value;
    if (key === 'all') return true;
    if (key === 'major') return !!entry.major;
    if (key === 'technical') return !!entry.technical;
    return kindOf(entry)?.toLowerCase() === key;
}

// Every entry this viewer may see: technical ones only when shown.
const visible = (entry) => technicalShown.value || !entry.technical;
// The major column: only major entries, never technical ones, days left empty dropped.
const majorDays = computed(() => keepDays((entry) => entry.major && !entry.technical && matches(entry)));
// The full column: every visible entry that passes the filter.
const fullDays = computed(() => keepDays((entry) => visible(entry) && matches(entry)));
const entryCount = computed(() => fullDays.value.reduce((total, day) => total + day.entries.length, 0));

// The statistic in the header: every entry this viewer may see, whatever the filter, and the first date.
const recordedDays = computed(() => keepDays(visible));
const recordedCount = computed(() => recordedDays.value.reduce((total, day) => total + day.entries.length, 0));
const firstDate = computed(() => recordedDays.value[recordedDays.value.length - 1]?.date ?? null);

// Whether the full changelog is open, remembered per viewer.
const fullOpen = ref(readFlag('changelog.fullOpen'));
watch(fullOpen, (value) => rememberFlag('changelog.fullOpen', value));

// The Admin setting: make technical entries available to everyone, or to the editors only.
const settingSaving = ref(false);
function setTechnicalEnabled(enabled) {
    router.post(
        '/changelog/settings',
        { technical_enabled: enabled },
        {
            preserveScroll: true,
            onStart: () => (settingSaving.value = true),
            onHttpException: onHttpException('The setting was not saved', (message) => toast.error(message)),
            onFinish: () => (settingSaving.value = false),
        },
    );
}

// Delete all: every entry, after a confirm that names the count. Editors get every entry, technical ones included, so
// the count is the whole changelog. It can be restored for 72 hours from the bar the server then sends.
const totalEntries = computed(() => props.days.reduce((total, day) => total + day.entries.length, 0));
const entriesLabel = (count) => (count === 1 ? '1 entry' : `${count} entries`);
const deleteAllOpen = ref(false);
const deletingAll = ref(false);
function deleteAll() {
    router.post(
        '/changelog/delete-all',
        {},
        {
            preserveScroll: true,
            onStart: () => (deletingAll.value = true),
            onSuccess: () => (deleteAllOpen.value = false),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Nothing was deleted. Try again.'),
            onHttpException: onHttpException('Nothing was deleted', (message) => toast.error(message)),
            onFinish: () => (deletingAll.value = false),
        },
    );
}

// `31OCT26 18:20Z`, the portal's Zulu stamp.
const zulu = (value) => `${forumDateTime(value).toUpperCase()}Z`;
const restoring = ref(false);
function restoreAll() {
    router.post(
        '/changelog/restore',
        {},
        {
            preserveScroll: true,
            onStart: () => (restoring.value = true),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Nothing was restored. Try again.'),
            onHttpException: onHttpException('Nothing was restored', (message) => toast.error(message)),
            onFinish: () => (restoring.value = false),
        },
    );
}

// Major and Technical exclude each other: ticking one clears the other.
function setKind(target, kind, value) {
    target[kind] = value === true;
    if (target[kind]) target[kind === 'major' ? 'technical' : 'major'] = false;
}

// The two columns, rendered with the same list. `prefix` keeps ids and test ids apart, since a major entry is in both.
const columns = computed(() => [
    { key: 'major', prefix: 'major-', days: majorDays.value },
    { key: 'full', prefix: '', days: fullDays.value },
]);

// Which entries have their details open, keyed by column prefix and id. All start collapsed.
const open = ref(new Set());
function toggle(id) {
    const next = new Set(open.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    open.value = next;
}

// The Add dialog: one date and its rows. The outdated bar's "Add entry" opens it with `?add=1`.
let nextKey = 1;
const blankRow = () => ({ key: nextKey++, tag: 'Added', body: '', major: false, technical: false, hasDetails: false, details: '' });
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
                return { body: withTag(row.tag, row.body), details: row.hasDetails && row.details.trim() !== '' ? row.details : null, major: row.major, technical: row.technical };
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
const editForm = useForm({ date: '', tag: 'Added', body: '', major: false, technical: false, hasDetails: false, details: '' });

function edit(date, entry) {
    importOpen.value = false;
    editForm.clearErrors();
    editForm.date = date;
    const { tag, text } = splitTag(entry.body ?? '');
    editForm.tag = tag;
    editForm.body = text;
    editForm.major = !!entry.major;
    editForm.technical = !!entry.technical;
    editForm.details = entry.details ?? '';
    editForm.hasDetails = (entry.details ?? '') !== '';
    editing.value = entry;
}

function submitEdit() {
    editForm
        .transform((data) => ({ date: data.date, body: withTag(data.tag, data.body), details: data.hasDetails && data.details.trim() !== '' ? data.details : null, major: data.major, technical: data.technical }))
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

    <div class="max-w-6xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Changelog</h1>
                <p v-if="recordedCount > 0" class="text-xs text-muted-foreground tabular-nums" data-testid="changelog-stat">
                    {{ recordedCount === 1 ? '1 change' : `${recordedCount} changes` }} recorded since {{ forumDate(firstDate).toUpperCase() }}
                </p>
            </div>
            <div v-if="isEditor || technicalSetting" class="flex flex-wrap gap-2">
                <template v-if="isEditor">
                    <Button size="sm" data-testid="changelog-add" @click="openAdd"><PhPlus aria-hidden="true" />Add</Button>
                    <Button size="sm" variant="outline" data-testid="changelog-import" @click="openImport"><PhUploadSimple aria-hidden="true" />Bulk import</Button>
                    <Button v-if="totalEntries > 0" size="sm" variant="outline" class="text-destructive" data-testid="changelog-delete-all" @click="deleteAllOpen = true">
                        <PhTrash aria-hidden="true" />Delete all
                    </Button>
                </template>
                <Popover v-if="technicalSetting">
                    <PopoverTrigger as-child>
                        <Button size="icon" variant="outline" class="size-8" aria-label="Changelog settings" title="Changelog settings" data-testid="changelog-settings">
                            <PhGear aria-hidden="true" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" class="w-72 space-y-1 p-3" data-testid="changelog-technical-setting">
                        <label class="flex items-center gap-2 text-[13px] font-medium">
                            <Checkbox
                                :model-value="technicalSetting.enabled"
                                :disabled="settingSaving"
                                data-testid="changelog-technical-setting-toggle"
                                @update:model-value="(value) => setTechnicalEnabled(value === true)"
                            />
                            Make technical and security items available to everyone
                        </label>
                        <p class="pl-6 text-xs text-muted-foreground">When off, only the changelog editors see them.</p>
                    </PopoverContent>
                </Popover>
            </div>
        </div>

        <Card v-if="isEditor && pendingRestore" class="max-w-3xl flex-row flex-wrap items-center justify-between gap-3 p-3" role="status" data-testid="changelog-restore-bar">
            <p class="flex items-center gap-2 text-[13px]">
                <PhClockCounterClockwise class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span data-testid="changelog-restore-text">
                    {{ entriesLabel(pendingRestore.count) }} deleted on {{ zulu(pendingRestore.deletedAt) }}. Restore until {{ zulu(pendingRestore.restoreUntil) }}.
                </span>
            </p>
            <Button size="sm" variant="outline" :disabled="restoring" data-testid="changelog-restore" @click="restoreAll">Restore</Button>
        </Card>

        <Card v-if="isEditor && importOpen" class="max-w-3xl gap-3 p-4" data-testid="changelog-import-panel">
            <h2 class="text-[15px] font-semibold">Bulk import</h2>
            <p class="text-[13px] text-muted-foreground">
                Start each date with a heading like "## 2026-10-29". Put each change under it on a line starting with "- ". Indent lines under a change
                to add additional information. Start a change with "[major]" to also list it under Major changes, or with "[technical]" to mark
                behind-the-scenes or security work.
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
                        <h3 class="text-xs font-semibold text-muted-foreground tabular-nums">{{ forumDate(day.date).toUpperCase() }}</h3>
                        <ul class="mt-1 space-y-0.5">
                            <li v-for="entry in day.entries" :key="entry.line" class="flex items-baseline gap-2" :data-testid="`preview-entry-${entry.line}`">
                                <span class="size-1.5 shrink-0 translate-y-[-2px] rounded-full bg-muted-foreground/60" aria-hidden="true" />
                                <span class="min-w-0 flex-1" :class="{ 'text-muted-foreground line-through': skipped(entry) }">
                                    {{ entry.body }}
                                    <span v-if="entry.details" class="text-muted-foreground" :data-testid="`preview-details-${entry.line}`">(with additional information)</span>
                                </span>
                                <Badge v-if="entry.major" variant="secondary" :data-testid="`preview-major-${entry.line}`">Major</Badge>
                                <Badge v-if="entry.technical" variant="outline" class="text-muted-foreground" :data-testid="`preview-technical-${entry.line}`">Technical</Badge>
                                <Badge v-if="skipped(entry)" variant="outline" :data-testid="`preview-skipped-${entry.line}`">Skipped, already exists</Badge>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </Card>

        <div v-if="days.length" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2" data-testid="changelog-filters">
            <div class="max-w-full overflow-x-auto">
                <ToggleGroup type="single" variant="segment" :model-value="activeFilter" aria-label="Show changes" @update:model-value="(value) => (filter = value || 'all')">
                    <ToggleGroupItem v-for="chip in filterChips" :key="chip.key" :value="chip.key" :data-testid="`changelog-filter-${chip.key}`">{{ chip.label }}</ToggleGroupItem>
                </ToggleGroup>
            </div>
            <label v-if="technicalToggle" class="flex w-fit items-center gap-2 text-xs text-muted-foreground">
                <Checkbox v-model="showTechnical" data-testid="changelog-technical-toggle" />
                Show technical and security items
            </label>
        </div>

        <div v-if="days.length" class="grid items-start gap-x-8 gap-y-6 lg:grid-cols-2" data-testid="changelog-columns">
            <section
                v-for="column in columns"
                :key="column.key"
                class="min-w-0"
                :aria-labelledby="`changelog-${column.key}-heading`"
                :data-testid="`changelog-column-${column.key}`"
            >
                <h2 v-if="column.key === 'major'" :id="`changelog-${column.key}-heading`" class="text-[15px] font-semibold">Major changes</h2>
                <h2 v-else :id="`changelog-${column.key}-heading`" class="text-[15px] font-semibold">
                    <button
                        type="button"
                        class="-ml-1 flex items-center gap-1.5 rounded-sm px-1 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-expanded="fullOpen"
                        aria-controls="changelog-full-list"
                        data-testid="changelog-full-toggle"
                        @click="fullOpen = !fullOpen"
                    >
                        <PhCaretRight class="size-3.5 text-muted-foreground transition-transform" :class="{ 'rotate-90': fullOpen }" weight="bold" aria-hidden="true" />
                        Full changelog
                        <span class="text-xs font-normal text-muted-foreground tabular-nums">{{ entryCount === 1 ? '1 entry' : `${entryCount} entries` }}</span>
                    </button>
                </h2>
                <p v-if="column.key === 'major' && column.days.length === 0" class="mt-2 text-[13px] text-muted-foreground" data-testid="changelog-major-empty">
                    {{ activeFilter === 'all' ? 'No major changes yet. Every change is in the full changelog.' : 'No major changes match this filter.' }}
                </p>
                <p v-else-if="column.key === 'full' && fullOpen && column.days.length === 0" class="mt-2 text-[13px] text-muted-foreground" data-testid="changelog-full-empty">
                    No changes match this filter.
                </p>
                <div
                    v-else-if="column.key === 'major' || fullOpen"
                    :id="column.key === 'full' ? 'changelog-full-list' : undefined"
                    class="mt-3 space-y-5"
                    :data-testid="column.key === 'full' ? 'changelog-days' : 'changelog-major-days'"
                >
                    <section v-for="day in column.days" :key="day.date" :data-testid="`${column.prefix}changelog-day-${day.date}`">
                        <h3 class="border-b border-border pb-1 text-[13px] font-semibold tabular-nums">
                            <time :datetime="day.date">{{ forumDate(day.date).toUpperCase() }}</time>
                        </h3>
                        <ul class="mt-1.5 space-y-0.5">
                            <li v-for="entry in day.entries" :key="entry.id" class="group" :data-testid="`${column.prefix}changelog-entry-${entry.id}`">
                                <div class="flex items-start gap-2 rounded-sm py-0.5 text-[13px] leading-5">
                                    <button
                                        v-if="entry.detailsHtml"
                                        type="button"
                                        class="mt-0.5 -ml-0.5 flex size-4 shrink-0 items-center justify-center rounded-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                        :aria-expanded="open.has(column.prefix + entry.id)"
                                        :aria-controls="`${column.prefix}changelog-details-${entry.id}`"
                                        :aria-label="open.has(column.prefix + entry.id) ? 'Hide additional information' : 'Show additional information'"
                                        :data-testid="`${column.prefix}changelog-toggle-${entry.id}`"
                                        @click="toggle(column.prefix + entry.id)"
                                    >
                                        <PhCaretRight class="size-3.5 transition-transform" :class="{ 'rotate-90': open.has(column.prefix + entry.id) }" weight="bold" />
                                    </button>
                                    <span v-else class="flex size-4 shrink-0 items-center justify-center" aria-hidden="true">
                                        <span class="size-1.5 rounded-full bg-muted-foreground/60" />
                                    </span>
                                    <span class="min-w-0 flex-1 break-words" data-testid="changelog-entry-body">{{ entry.body }}</span>
                                    <Badge v-if="column.key === 'full' && entry.major" variant="secondary" class="h-5 shrink-0 px-1.5 text-[11px]" :data-testid="`changelog-major-${entry.id}`">Major</Badge>
                                    <Badge v-if="kindOf(entry) === 'Security'" variant="secondary" class="h-5 shrink-0 px-1.5 text-[11px]" :data-testid="`${column.prefix}changelog-security-${entry.id}`">Security</Badge>
                                    <Badge
                                        v-if="entry.technical"
                                        variant="outline"
                                        class="h-5 shrink-0 px-1.5 text-[11px] text-muted-foreground"
                                        :data-testid="`changelog-technical-${entry.id}`"
                                    >
                                        Technical
                                    </Badge>
                                    <span v-if="isEditor" class="flex shrink-0 gap-0.5 sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100">
                                        <Button variant="ghost" size="icon" class="size-6" aria-label="Edit entry" :data-testid="`${column.prefix}changelog-edit-${entry.id}`" @click="edit(day.date, entry)">
                                            <PhPencilSimple class="size-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-6 text-destructive"
                                            aria-label="Delete entry"
                                            :data-testid="`${column.prefix}changelog-delete-${entry.id}`"
                                            @click="requestDelete(entry)"
                                        >
                                            <PhTrash class="size-3.5" />
                                        </Button>
                                    </span>
                                </div>
                                <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped -->
                                <div
                                    v-if="entry.detailsHtml && open.has(column.prefix + entry.id)"
                                    :id="`${column.prefix}changelog-details-${entry.id}`"
                                    class="docs-prose mb-1.5 ml-6 border-l-2 border-border pl-3 text-[13px] text-muted-foreground"
                                    :data-testid="`${column.prefix}changelog-details-${entry.id}`"
                                    v-html="entry.detailsHtml"
                                />
                            </li>
                        </ul>
                    </section>
                </div>
            </section>
        </div>
        <EmptyState v-else :icon="PhClockCounterClockwise" title="No entries yet" class="py-10" data-testid="changelog-empty">
            Changes to the portal are listed here once the changelog editors write them.
        </EmptyState>

        <Dialog v-if="isEditor" :open="addOpen" @update:open="(value) => !addForm.processing && (addOpen = value)">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl" data-testid="changelog-add-dialog">
                <DialogHeader>
                    <DialogTitle>Add entries</DialogTitle>
                    <DialogDescription>One change per row. Press Enter in a row to add one below it. Every change goes in the full changelog; tick Major change to also list it under Major changes, or Technical for behind-the-scenes and security work.</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submitAdd">
                    <div class="space-y-1.5">
                        <Label for="changelog-add-date">Date (Zulu)</Label>
                        <Input id="changelog-add-date" v-model="addForm.date" type="date" class="w-44" data-testid="changelog-add-date" :aria-invalid="!!addForm.errors.date" />
                    </div>

                    <ol class="space-y-2" data-testid="changelog-rows">
                        <li v-for="(row, index) in addForm.rows" :key="row.key" class="space-y-1.5" :data-testid="`changelog-row-${index}`">
                            <div class="flex items-center gap-1.5">
                                <div class="w-28 shrink-0">
                                    <NativeSelect v-model="row.tag" :aria-label="`Tag for change ${index + 1}`" :data-testid="`changelog-row-tag-${index}`">
                                        <NativeSelectOption v-for="kind in KINDS" :key="kind" :value="kind">{{ kind }}</NativeSelectOption>
                                    </NativeSelect>
                                </div>
                                <Input
                                    :ref="(el) => (el ? rowInputs.set(row.key, el.$el ?? el) : rowInputs.delete(row.key))"
                                    v-model="row.body"
                                    :maxlength="textRoom(row.tag)"
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
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                                <label class="flex items-center gap-2">
                                    <Checkbox :model-value="row.major" :data-testid="`changelog-row-major-${index}`" @update:model-value="(value) => setKind(row, 'major', value)" />
                                    Major change
                                </label>
                                <label class="flex items-center gap-2">
                                    <Checkbox :model-value="row.technical" :data-testid="`changelog-row-technical-${index}`" @update:model-value="(value) => setKind(row, 'technical', value)" />
                                    Technical
                                </label>
                                <label class="flex items-center gap-2">
                                    <Checkbox v-model="row.hasDetails" :data-testid="`changelog-row-has-details-${index}`" />
                                    Add additional information
                                </label>
                            </div>
                            <p v-if="row.technical || row.tag === 'Security'" class="text-xs text-muted-foreground" :data-testid="`changelog-row-technical-hint-${index}`">Say what was fixed, not how it could be abused.</p>
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
                        <div class="flex items-center gap-1.5">
                            <div class="w-28 shrink-0">
                                <NativeSelect v-model="editForm.tag" aria-label="Tag" data-testid="changelog-edit-tag">
                                    <NativeSelectOption v-for="kind in KINDS" :key="kind" :value="kind">{{ kind }}</NativeSelectOption>
                                </NativeSelect>
                            </div>
                            <Input id="changelog-edit-body" v-model="editForm.body" class="flex-1" :maxlength="textRoom(editForm.tag)" data-testid="changelog-edit-body" :aria-invalid="!!editForm.errors.body" />
                        </div>
                        <p v-if="editForm.errors.body" class="text-xs text-destructive">{{ editForm.errors.body }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-muted-foreground">
                        <Checkbox :model-value="editForm.major" data-testid="changelog-edit-major" @update:model-value="(value) => setKind(editForm, 'major', value)" />
                        Major change
                    </label>
                    <label class="flex items-center gap-2 text-xs text-muted-foreground">
                        <Checkbox :model-value="editForm.technical" data-testid="changelog-edit-technical" @update:model-value="(value) => setKind(editForm, 'technical', value)" />
                        Technical
                    </label>
                    <p v-if="editForm.technical || editForm.tag === 'Security'" class="text-xs text-muted-foreground" data-testid="changelog-edit-technical-hint">Say what was fixed, not how it could be abused.</p>
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

        <Dialog v-if="isEditor" :open="deleteAllOpen" @update:open="(value) => !deletingAll && (deleteAllOpen = value)">
            <DialogContent class="sm:max-w-md" data-testid="changelog-delete-all-dialog">
                <DialogHeader>
                    <DialogTitle>Delete all {{ entriesLabel(totalEntries) }}?</DialogTitle>
                    <DialogDescription>You can restore them for 72 hours.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="ghost" size="sm" :disabled="deletingAll" @click="deleteAllOpen = false">Cancel</Button>
                    <Button type="button" variant="destructive" size="sm" :disabled="deletingAll" data-testid="changelog-delete-all-confirm" @click="deleteAll">
                        Delete all
                    </Button>
                </DialogFooter>
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
