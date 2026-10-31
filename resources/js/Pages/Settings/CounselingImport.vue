<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PhDownloadSimple, PhLockSimple, PhMagnifyingGlass, PhTerminalWindow, PhTrayArrowDown } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';
import { forumDateTime } from '@/lib/forumDate';
import { importableCount, importDate, readAllImportFields, sourceLabel } from '@/lib/counselingImport';

// The counseling import (#565), Admins only: older DCSs and LORs brought in from their Drive links or PDFs. The Admin
// fills in the CSV template, uploads it with the PDFs it names, and checks: their browser reads each PDF's form first
// (counselingImport.js) and sends what it read with the files. The check is a dry run, row by row; the import is
// queued and stores the import and needs-review rows only. The console command runs the same import.
const props = defineProps({
    // The template's columns, in order.
    columns: { type: Array, required: true },
    // `{ rows, files, fileBytes }`: how many rows and PDFs one check takes, and the largest PDF.
    limits: { type: Object, required: true },
    // The last check, or null: `{ batch, csvName, checkedAt, dryRun, counts: { all, import, review, skipped }, rows }`,
    // each row `{ line, source, label, outcome, reason, notes, type, trooper, issuer, counseledOn, candidates, recordId }`.
    check: { type: Object, default: null },
    // Once the check was imported: `{ state, reason, report, updatedAt }`, `state` one of queued, running, finished,
    // refused or failed, `reason` plain English for a refused or failed run, `report` the run's rows (as `check`'s)
    // once it has them. Reloaded every few seconds while queued or running.
    run: { type: Object, default: null },
    // The batch's files on the server could not be read.
    error: { type: String, default: null },
    status: { type: String, default: null },
});

// How often the page asks how a queued or running import stands.
const POLL_MS = 3000;

const csv = ref(null);
const pdfs = ref([]);
const checking = ref(false);
const reading = ref(false);
const importing = ref(false);
const errors = ref({});
const filter = ref('all');
// The trooper the Admin picked for each row whose Last.F several troopers share: `{ [line]: trooperId }`.
const picks = reactive({});

// After the import: its own report, rows skipped only at import time included; before it, the check.
const result = computed(() => props.run?.report ?? props.check);
const imported = computed(() => Boolean(props.run?.report));
const running = computed(() => ['queued', 'running'].includes(props.run?.state));
const rows = computed(() => result.value?.rows ?? []);
const shown = computed(() => (filter.value === 'all' ? rows.value : rows.value.filter((row) => row.outcome === filter.value)));
const toImport = computed(() => importableCount(rows.value, picks));
const tooLarge = computed(() => pdfs.value.filter((file) => file.size > props.limits.fileBytes).map((file) => file.name));

const tabs = computed(() => [
    { value: 'all', label: 'All', count: result.value?.counts.all ?? 0 },
    { value: 'import', label: imported.value ? 'Imported' : 'Import', count: result.value?.counts.import ?? 0 },
    { value: 'review', label: 'Needs review', count: result.value?.counts.review ?? 0 },
    { value: 'skipped', label: 'Skipped', count: result.value?.counts.skipped ?? 0 },
]);

// The first error under any `files.*` key, then `files` itself.
const filesError = computed(() => errors.value.files ?? Object.entries(errors.value).find(([key]) => key.startsWith('files.'))?.[1] ?? null);

const runLine = computed(() => {
    const run = props.run;
    if (!run) return null;
    if (run.state === 'queued') return 'Import queued. This page updates on its own.';
    if (run.state === 'running') return 'Importing. This page updates on its own.';
    if (run.state === 'finished') {
        const counts = run.report.counts;
        return `Import finished: ${counts.import + counts.review} stored (${counts.review} needing review), ${counts.skipped} skipped.`;
    }

    return run.reason ?? 'The import did not finish.';
});

let poll = null;
watch(
    running,
    (now) => {
        clearInterval(poll);
        poll = now ? setInterval(() => router.reload({ only: ['run'] }), POLL_MS) : null;
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));

const typeLabel = (type) => (type ? type.toUpperCase() : '?');
const dash = (row, value, unread = 'Not read') => (row.outcome === 'skipped' && !value ? '-' : value || unread);

function chooseCsv(event) {
    csv.value = event.target.files?.[0] ?? null;
}

function choosePdfs(event) {
    pdfs.value = Array.from(event.target.files ?? []);
}

async function checkFiles() {
    errors.value = {};

    if (!csv.value) {
        errors.value = { csv: 'Choose the CSV file.' };
        return;
    }
    if (pdfs.value.length > props.limits.files) {
        errors.value = { files: `Upload at most ${props.limits.files} PDFs at a time.` };
        return;
    }
    if (tooLarge.value.length) {
        errors.value = { files: `Each PDF must be 10 MB or smaller: ${tooLarge.value.join(', ')}.` };
        return;
    }

    checking.value = true;
    reading.value = true;
    let fields;
    try {
        fields = await readAllImportFields(pdfs.value);
    } catch (error) {
        console.error('Counseling import: the PDF reader did not load.', error);
        toast.error('The PDF reader did not load. Reload the page and check the files again.');
        checking.value = false;
        return;
    } finally {
        reading.value = false;
    }

    router.post(
        '/settings/counseling-import/check',
        { csv: csv.value, files: pdfs.value, fields: JSON.stringify(fields) },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                filter.value = 'all';
                for (const line of Object.keys(picks)) delete picks[line];
            },
            onError: (failed) => (errors.value = failed),
            onFinish: () => (checking.value = false),
            onHttpException: onHttpException('The files were not checked', (message) => toast.error(message)),
        },
    );
}

function importRecords() {
    importing.value = true;
    router.post(
        `/settings/counseling-import/${props.check.batch}`,
        { picks: Object.fromEntries(Object.entries(picks).filter(([, trooper]) => trooper)) },
        {
            preserveScroll: true,
            onError: (failed) => toast.error(Object.values(failed)[0] ?? 'The import did not start. Check the files again.'),
            onFinish: () => (importing.value = false),
            onHttpException: onHttpException('The import did not start', (message) => toast.error(message)),
        },
    );
}

function resultLabel(row) {
    if (row.outcome === 'import') return imported.value ? 'Imported' : 'Import';
    if (row.outcome === 'review') return imported.value ? 'Imported, needs review' : 'Needs review';

    return `Skipped: ${row.reason}`;
}
</script>

<template>
    <Head title="Settings · Counseling import · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Counseling import</h1>
                <p class="mt-1 max-w-prose text-[13px] text-muted-foreground">
                    Bring in DCSs and LORs that live as PDFs on Google Drive. Admins only. Nothing is saved until you confirm the
                    check.
                </p>
                <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground">
                    <PhTerminalWindow aria-hidden="true" class="size-4" />Same import from the server:
                    <code class="rounded bg-muted px-1.5 py-0.5 font-mono text-[12px] text-foreground">php artisan counseling:import file.csv --files=pdfs/ --dry-run</code>
                </p>
            </div>
            <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
        </div>

        <Card v-if="status" class="p-4 text-[13px]" role="status" data-testid="import-status">{{ status }}</Card>
        <Card v-if="error" class="p-4 text-[13px] text-destructive" role="alert" data-testid="import-error">{{ error }}</Card>
        <Card
            v-if="runLine"
            class="p-4 text-[13px]"
            :class="['refused', 'failed'].includes(run.state) ? 'text-destructive' : ''"
            :role="['refused', 'failed'].includes(run.state) ? 'alert' : 'status'"
            data-testid="import-run"
        >
            {{ runLine }}
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card class="min-w-0 gap-0 py-0">
                <h2 class="border-b border-border px-4 py-3 text-[15px] font-semibold">1. Fill in the template</h2>
                <div class="space-y-3 px-4 py-4 text-[13px]">
                    <p>
                        One row per document. Give a <code class="rounded bg-muted px-1 font-mono">drive_url</code> or the
                        <code class="rounded bg-muted px-1 font-mono">file</code> name of a PDF you upload next. The other columns are
                        optional: the portal reads them from the form when it can.
                    </p>
                    <div class="flex flex-wrap gap-1.5" data-testid="template-columns">
                        <code v-for="column in columns" :key="column" class="rounded border border-border px-1.5 py-0.5 font-mono text-[12px]">{{ column }}</code>
                    </div>
                    <p class="text-muted-foreground">
                        <code class="font-mono">type</code> is DCS or LOR; <code class="font-mono">kind</code> is conduct (the default) or
                        missed_practice; <code class="font-mono">trooper</code> and <code class="font-mono">issuer</code> are Last.F;
                        <code class="font-mono">counseled_on</code> is YYYY-MM-DD.
                    </p>
                    <Button as="a" href="/settings/counseling-import/template" variant="outline" data-testid="download-template">
                        <PhDownloadSimple aria-hidden="true" />Download CSV template
                    </Button>
                </div>
            </Card>

            <Card class="min-w-0 gap-0 py-0">
                <h2 class="border-b border-border px-4 py-3 text-[15px] font-semibold">2. Upload and check</h2>
                <form class="space-y-3 px-4 py-4 text-[13px]" @submit.prevent="checkFiles">
                    <div class="space-y-1">
                        <label for="import-csv" class="text-muted-foreground">CSV file</label>
                        <input
                            id="import-csv"
                            type="file"
                            accept=".csv,text/csv"
                            data-testid="csv-input"
                            class="block w-full text-[13px] file:mr-3 file:rounded-md file:border file:border-border file:bg-card file:px-3 file:py-1.5 file:text-[13px]"
                            @change="chooseCsv"
                        />
                        <p v-if="errors.csv" class="text-destructive" data-testid="csv-error">{{ errors.csv }}</p>
                    </div>
                    <div class="space-y-1">
                        <label for="import-pdfs" class="text-muted-foreground">PDFs named in the file column (optional)</label>
                        <input
                            id="import-pdfs"
                            type="file"
                            accept=".pdf,application/pdf"
                            multiple
                            data-testid="pdf-input"
                            class="block w-full text-[13px] file:mr-3 file:rounded-md file:border file:border-border file:bg-card file:px-3 file:py-1.5 file:text-[13px]"
                            @change="choosePdfs"
                        />
                        <p v-if="filesError" class="text-destructive" data-testid="files-error">{{ filesError }}</p>
                        <p v-if="errors.fields" class="text-destructive">{{ errors.fields }}</p>
                    </div>
                    <p class="text-muted-foreground">
                        Drive links must be shared as "Anyone with the link". Folder links are not supported. Up to {{ limits.rows }} rows at
                        a time.
                    </p>
                    <Button type="submit" :disabled="checking" data-testid="check-files">
                        <PhMagnifyingGlass aria-hidden="true" />{{ reading ? 'Reading PDFs' : checking ? 'Checking' : 'Check files' }}
                    </Button>
                </form>
            </Card>
        </div>

        <Card v-if="check" class="min-w-0 gap-0 py-0" data-testid="check-result">
            <div class="flex flex-wrap items-baseline gap-x-2 border-b border-border px-4 py-3">
                <h2 class="text-[15px] font-semibold">{{ imported ? '3. Import result' : '3. Check result' }}</h2>
                <span class="text-[13px] text-muted-foreground">
                    {{ check.csvName }} · {{ check.counts.all }} {{ check.counts.all === 1 ? 'row' : 'rows' }} · checked {{ forumDateTime(check.checkedAt) }}Z
                </span>
            </div>

            <div class="px-4 pt-3">
                <Tabs v-model="filter">
                    <TabsList aria-label="Filter rows">
                        <TabsTrigger v-for="tab in tabs" :key="tab.value" :value="tab.value" :data-testid="`filter-${tab.value}`">
                            {{ tab.label }} <Badge variant="outline">{{ tab.count }}</Badge>
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            <div class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-14">Row</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Trooper</TableHead>
                            <TableHead>Issuer</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Result</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in shown" :key="row.line" :data-testid="`row-${row.line}`">
                            <TableCell class="text-muted-foreground">{{ row.line }}</TableCell>
                            <TableCell class="max-w-56 truncate font-mono text-[12px]" :title="row.label">{{ sourceLabel(row) }}</TableCell>
                            <TableCell>{{ row.outcome === 'skipped' && !row.type ? '-' : typeLabel(row.type) }}</TableCell>
                            <TableCell>
                                <NativeSelect
                                    v-if="row.candidates.length && !run"
                                    v-model="picks[row.line]"
                                    :aria-label="`Trooper for row ${row.line}`"
                                    :data-testid="`pick-${row.line}`"
                                >
                                    <NativeSelectOption value="">{{ row.candidates.length }} match "{{ row.trooper }}"</NativeSelectOption>
                                    <NativeSelectOption v-for="candidate in row.candidates" :key="candidate.id" :value="candidate.id">
                                        {{ candidate.name }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <template v-else>{{ dash(row, row.trooper, '-') }}</template>
                            </TableCell>
                            <TableCell>{{ dash(row, row.issuer) }}</TableCell>
                            <TableCell>{{ dash(row, importDate(row.counseledOn)) }}</TableCell>
                            <TableCell class="max-w-80">
                                <Badge
                                    :variant="row.outcome === 'import' ? 'success' : row.outcome === 'review' ? 'warning' : 'outline'"
                                    class="h-auto whitespace-normal text-left"
                                    :data-testid="`result-${row.line}`"
                                >
                                    {{ resultLabel(row) }}
                                </Badge>
                                <p v-if="row.outcome === 'review' && row.reason" class="mt-1 text-[12px] text-muted-foreground">{{ row.reason }}</p>
                                <a v-if="row.recordId" :href="`/counseling/${row.recordId}`" class="mt-1 block text-[12px] underline" :data-testid="`record-${row.line}`">Open the record</a>
                                <p v-for="note in row.notes" :key="note" class="mt-1 text-[12px] text-muted-foreground">{{ note }}</p>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="!run" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <p class="text-[13px] text-muted-foreground">
                    Rows needing review are imported and flagged for you to complete. Skipped rows, and rows still waiting for a trooper
                    to be picked, are left out.
                </p>
                <Button :disabled="importing || toImport === 0" data-testid="import-records" @click="importRecords">
                    <PhTrayArrowDown aria-hidden="true" />Import {{ toImport }} {{ toImport === 1 ? 'record' : 'records' }}
                </Button>
            </div>
        </Card>
    </div>
</template>
