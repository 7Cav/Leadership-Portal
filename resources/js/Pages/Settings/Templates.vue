<script setup>
import { computed, onBeforeUnmount, ref, toRef, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { refDebounced } from '@vueuse/core';
import { PhCaretDown, PhCheck, PhCircleNotch, PhCopy, PhFileCode, PhLockSimple, PhWarning } from '@phosphor-icons/vue';
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { render, sampleContext, templateLegend } from '@/lib/bbcode';
import { useViewAs } from '@/composables/useViewAs';
import BbcodePreview from '@/components/BbcodePreview.vue';
import EmptyState from '@/components/EmptyState.vue';
import { toast } from 'vue-sonner';
import InlineNotice from '@/components/InlineNotice.vue';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';

// Rollcall template authoring (Story 3.5, AD-17). Everything is shaped by `Settings\TemplatesController`. A save is
// always a new version and a rollback only moves the active pointer: the page has no way to change a version that
// exists. The controller enforces the Admin gate on every write, so what the controls do here is only cosmetic (AD-6).
//
// While an Admin previews a leader (view-as, 1.13) the editor stays on the page but disabled, with the reason on a
// tooltip, as on the roster: the page explains why it cannot act instead of silently losing the controls. Only an
// Admin can preview, so a preview with no Admin rights on the page is always that case.
const props = defineProps({
    artifactType: { type: Object, required: true },
    companies: { type: Array, required: true },
    company: { type: Number, default: null },
    active: { type: Object, default: null },
    history: { type: Array, required: true },
    maxBodyLength: { type: Number, required: true },
    // A template to start from (Story 3.10).
    starter: { type: String, required: true },
    isAdmin: { type: Boolean, required: true },
});

const form = useForm({
    company_id: props.company,
    body: props.active?.body ?? '',
    reason: '',
});

// Another company, or a new active version after a save or rollback, starts the editor from that version's text.
watch(
    () => [props.company, props.active?.id],
    () => {
        form.company_id = props.company;
        form.body = props.active?.body ?? '';
        form.reset('reason');
        form.clearErrors();
    },
);

// Text typed into the editor that is not saved yet: the other company's template would replace it.
const unsaved = computed(() => form.body !== (props.active?.body ?? '') || form.reason.trim() !== '');
// Redraws the company picker on the company still open when the Admin decides to stay.
const pickerKey = ref(0);

function chooseCompany(event) {
    // Asks before dropping a draft, as the report and rollcall pages do when leaving with unsaved changes.
    if (unsaved.value && !window.confirm('Your template changes are not saved. Switch company and lose them?')) {
        pickerKey.value++;

        return;
    }

    router.get('/settings/templates', { company: event.target.value }, { preserveScroll: true });
}

function save() {
    form.post('/settings/templates', {
        preserveScroll: true,
        onHttpException: onHttpException('The template was not saved', (message) => toast.error(message)),
    });
}

const { active: previewing, subject: previewSubject } = useViewAs();
const readOnlyPreview = computed(() => !props.isAdmin && previewing.value);
const showEditor = computed(() => props.isAdmin || previewing.value);
const readOnlyReason = computed(() => `Read-only while viewing as ${previewSubject.value}`);

// A rollback needs its own reason (AD-16): it is asked for in a dialog with a labelled field, an inline error and a
// pending state, and the confirm button stays disabled while the request runs so a double-click cannot send two.
const target = ref(null);
const reason = ref('');
const activateError = ref(null);
const activating = ref(false);
const reasonBlank = computed(() => reason.value.trim() === '');

function requestActivate(version) {
    target.value = version;
    reason.value = '';
    activateError.value = null;
}

function closeDialog() {
    if (!activating.value) target.value = null;
}

function confirmActivate() {
    if (reasonBlank.value || activating.value) return;

    activateError.value = null;
    router.post(`/settings/templates/${target.value.id}/activate`, { reason: reason.value.trim() }, {
        preserveScroll: true,
        onStart: () => {
            activating.value = true;
        },
        onSuccess: () => {
            target.value = null;
        },
        onError: (errors) => {
            activateError.value = errors.reason ?? 'The activation failed. Reload the page and try again.';
        },
        onFinish: () => {
            activating.value = false;
        },
    });
}

// Which version's text is open for reading, so an Admin sees what a rollback would bring back.
const viewing = ref(null);
const toggleView = (version) => {
    viewing.value = viewing.value === version.id ? null : version.id;
};

// Every code the template language accepts, grouped, with what it means and an example (Story 3.10). It is built from the
// lists the parser itself reads (`lib/bbcode.js`, held to the server's by the shared cases), so it cannot list a code
// the save would refuse or miss one it accepts. The codes are strings here because Vue's own template syntax would read
// double braces written in the markup as an interpolation.
const legend = templateLegend();
const EXAMPLES = { print: '{{name}}', loop: '{{#troopers}}...{{/troopers}}', inverted: '{{^name}}...{{/name}}' };

// Puts one code on the clipboard; its button shows a tick for a moment after.
const copiedCode = ref(null);
let copiedTimer = null;
async function copyCode(code) {
    try {
        await navigator.clipboard.writeText(code);
    } catch {
        return;
    }
    copiedCode.value = code;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => {
        copiedCode.value = null;
    }, 1500);
}
onBeforeUnmount(() => clearTimeout(copiedTimer));

// The template filled with a made-up section (the legend's examples), drawn as the forum shows the post. It follows the
// editor a moment after the Admin stops typing, so a long template is not redrawn on every key.
const SAMPLE = sampleContext();
const typedBody = refDebounced(toRef(form, 'body'), 250);
const previewSource = computed(() => (showEditor.value ? typedBody.value : (props.active?.body ?? '')));
const preview = computed(() => (previewSource.value.trim() === '' ? null : render(previewSource.value, SAMPLE)));

// Fills the editor with the starter, only when an Admin asks, so nobody saves it unread.
function useStarter() {
    form.body = props.starter;
}

const companyLabel = (company) => (company.callsign ? `${company.callsign} ${company.name}` : company.name);
</script>

<template>
    <Head title="Settings · Templates · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Templates</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    The {{ artifactType.label }} every section under a company renders from. An edit saves a new version and makes it the active one;
                    older versions are kept as they were.
                </p>
            </div>
            <Badge v-if="isAdmin" variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
        </div>

        <EmptyState v-if="!companies.length" :icon="PhFileCode" title="No companies yet." data-testid="no-companies">
            Templates belong to a company. Add one in the org structure first.
        </EmptyState>

        <template v-else>
            <div class="grid max-w-sm gap-1.5">
                <Label for="template-company">Company</Label>
                <NativeSelect id="template-company" :key="pickerKey" data-testid="company-select" :model-value="company" @change="chooseCompany">
                    <option v-for="item in companies" :key="item.id" :value="item.id">{{ companyLabel(item) }}</option>
                </NativeSelect>
            </div>

            <div class="grid items-start gap-5 lg:grid-cols-[1.2fr_0.8fr]">
                <Card class="min-w-0 gap-0 p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="text-[15px] font-semibold">Active template</h2>
                        <Badge v-if="active" variant="success" data-testid="active-version">Version {{ active.version }}</Badge>
                    </div>

                    <p v-if="!active" class="mb-3 text-[13px] text-muted-foreground" data-testid="no-active">
                        This company has no template yet, so its sections show no rollcall BBCode.
                        {{ isAdmin ? 'Write the first one below.' : 'An Admin can write one here.' }}
                    </p>

                    <form v-if="showEditor" class="space-y-3" data-testid="template-form" @submit.prevent="save">
                        <div class="grid gap-1.5">
                            <Label for="template-body">Template</Label>
                            <Textarea
                                id="template-body"
                                v-model="form.body"
                                data-testid="template-body"
                                rows="16"
                                class="font-mono text-xs"
                                :maxlength="maxBodyLength"
                                :disabled="readOnlyPreview"
                                :aria-invalid="!!form.errors.body"
                            />
                            <Button v-if="!active && !readOnlyPreview" type="button" variant="outline" size="sm" class="justify-self-start" data-testid="use-starter" @click="useStarter">
                                Start from an example
                            </Button>
                            <p v-if="form.errors.body" class="text-xs text-destructive" data-testid="error-body">{{ form.errors.body }}</p>
                            <p v-if="form.errors.company_id" class="text-xs text-destructive" data-testid="error-company_id">{{ form.errors.company_id }}</p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="template-reason">Reason</Label>
                            <Textarea id="template-reason" v-model="form.reason" data-testid="template-reason" rows="2" :disabled="readOnlyPreview" :aria-invalid="!!form.errors.reason" />
                            <p class="text-xs text-muted-foreground">Recorded in the audit log with the new version.</p>
                            <p v-if="form.errors.reason" class="text-xs text-destructive" data-testid="error-reason">{{ form.errors.reason }}</p>
                        </div>

                        <TooltipProvider v-if="readOnlyPreview">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button type="button" aria-disabled="true" class="cursor-not-allowed opacity-60" data-testid="save-template-readonly" @click.prevent>
                                        <PhLockSimple aria-hidden="true" />Save as a new version
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>{{ readOnlyReason }}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <Button v-else type="submit" data-testid="save-template" :disabled="form.processing">Save as a new version</Button>
                    </form>

                    <pre v-else-if="active" class="overflow-x-auto rounded-md border border-border bg-muted/30 p-3 font-mono text-xs whitespace-pre-wrap" data-testid="template-readonly">{{ active.body }}</pre>

                    <section v-if="preview" class="mt-5 border-t border-border pt-4" aria-labelledby="template-preview-title" data-testid="template-preview">
                        <div class="mb-2.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <h3 id="template-preview-title" class="text-[13px] font-semibold">Preview</h3>
                            <p class="text-xs text-muted-foreground">
                                As the forum shows it, filled with a sample section of {{ SAMPLE.troopers.length }} troopers. Updates as you type.
                            </p>
                        </div>
                        <div v-if="preview.ok" class="max-h-[28rem] overflow-auto rounded-md border border-border bg-background px-5 py-4" data-testid="template-preview-post">
                            <BbcodePreview :source="preview.text" />
                        </div>
                        <InlineNotice v-else :icon="PhWarning" tone="warning" role="status" data-testid="template-preview-error">
                            The preview shows again once the template codes are valid. {{ preview.error }}
                        </InlineNotice>
                    </section>

                </Card>

                <Card class="min-w-0 gap-0 py-0">
                    <div class="px-4 pt-3.5 pb-2">
                        <h2 class="text-[15px] font-semibold">Versions</h2>
                    </div>
                    <Table v-if="history.length" sticky-header class="text-[13px]">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Version</TableHead>
                                <TableHead class="h-8 text-xs font-medium text-muted-foreground">Saved</TableHead>
                                <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-for="version in history" :key="version.id">
                                <TableRow :data-testid="`version-row-${version.id}`">
                                    <TableCell class="px-4 font-medium">
                                        {{ version.version }}
                                        <Badge v-if="version.isActive" variant="success" class="ml-1.5" data-testid="active-badge">Active</Badge>
                                    </TableCell>
                                    <TableCell class="text-muted-foreground">
                                        {{ forumDate(version.createdAt) }}<template v-if="version.createdBy"> by {{ version.createdBy }}</template>
                                    </TableCell>
                                    <TableCell class="px-4 text-right whitespace-nowrap">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :aria-expanded="viewing === version.id"
                                            :aria-controls="`version-body-${version.id}`"
                                            :data-testid="`view-${version.id}`"
                                            @click="toggleView(version)"
                                        >
                                            {{ viewing === version.id ? 'Hide' : 'View' }}
                                        </Button>
                                        <template v-if="showEditor && !version.isActive">
                                            <TooltipProvider v-if="readOnlyPreview">
                                                <Tooltip>
                                                    <TooltipTrigger as-child>
                                                        <Button variant="ghost" size="sm" aria-disabled="true" class="cursor-not-allowed opacity-60" :data-testid="`activate-${version.id}`" @click.prevent>
                                                            <PhLockSimple aria-hidden="true" />Make active
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{{ readOnlyReason }}</TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                            <Button v-else variant="ghost" size="sm" :data-testid="`activate-${version.id}`" @click="requestActivate(version)">Make active</Button>
                                        </template>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="viewing === version.id" class="hover:bg-transparent">
                                    <TableCell colspan="3" class="px-4 pb-3">
                                        <pre
                                            :id="`version-body-${version.id}`"
                                            class="max-h-72 overflow-auto rounded-md border border-border bg-muted/30 p-3 font-mono text-xs whitespace-pre-wrap"
                                            :data-testid="`version-body-${version.id}`"
                                        >{{ version.body }}</pre>
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                    <EmptyState v-else :icon="PhFileCode" title="No versions yet." class="border-t border-border py-10" data-testid="no-versions" />
                </Card>
            </div>

            <details open class="group rounded-xl border border-border bg-card" data-testid="syntax-help">
                <summary class="flex cursor-pointer list-none items-center gap-2 rounded-xl px-5 py-4 [&::-webkit-details-marker]:hidden">
                    <h2 class="text-[15px] font-semibold">Template codes</h2>
                    <PhCaretDown class="size-4 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="border-t border-border px-5 pt-4 pb-6">
                    <p class="max-w-[75ch] text-[13px] leading-relaxed text-muted-foreground">
                        Write the BBCode as normal and put these codes where the week's data goes.
                        <code class="rounded-sm bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{{ EXAMPLES.print }}</code> prints a value,
                        <code class="rounded-sm bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{{ EXAMPLES.loop }}</code> repeats for each trooper, and
                        <code class="rounded-sm bg-muted px-1 py-0.5 font-mono text-xs text-foreground">{{ EXAMPLES.inverted }}</code> shows when a value or flag
                        is not set. A code that is not listed here is refused when you save.
                    </p>

                    <div class="mt-7 space-y-9">
                        <section v-for="group in legend" :key="group.key" :aria-labelledby="`legend-title-${group.key}`" :data-testid="`legend-${group.key}`">
                            <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 border-b border-border pb-2">
                                <h3 :id="`legend-title-${group.key}`" class="text-[13px] font-semibold tracking-tight">{{ group.title }}</h3>
                                <span v-if="group.where" class="text-xs text-muted-foreground" data-testid="legend-where">{{ group.where }}</span>
                            </div>
                            <ul class="grid gap-x-10 lg:grid-cols-2">
                                <li
                                    v-for="entry in group.entries"
                                    :key="entry.name"
                                    class="border-b border-border/60 py-3.5 lg:[&:nth-last-child(2):nth-child(odd)]:border-b-0 last:border-b-0"
                                    :data-testid="`legend-entry-${entry.name}`"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <code class="rounded-sm border border-border bg-muted px-1.5 py-0.5 font-mono text-xs break-all text-foreground" data-testid="legend-code">{{
                                            entry.code
                                        }}</code>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="-my-1 size-7 shrink-0 text-muted-foreground hover:text-foreground"
                                            :aria-label="copiedCode === entry.code ? `Copied ${entry.code}` : `Copy ${entry.code}`"
                                            :data-testid="`legend-copy-${entry.name}`"
                                            @click="copyCode(entry.code)"
                                        >
                                            <PhCheck v-if="copiedCode === entry.code" class="text-success" aria-hidden="true" />
                                            <PhCopy v-else aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <p class="mt-2 text-[13px] leading-relaxed text-foreground/85" data-testid="legend-meaning">{{ entry.meaning }}.</p>
                                    <p class="mt-1.5 flex items-baseline gap-2 text-xs">
                                        <span class="shrink-0 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Example</span>
                                        <code class="font-mono break-all text-muted-foreground" data-testid="legend-example">{{ entry.example }}</code>
                                    </p>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </details>
        </template>
        <AlertDialog :open="target !== null" @update:open="(open) => !open && closeDialog()">
            <AlertDialogContent class="sm:max-w-md" data-testid="activate-dialog">
                <AlertDialogTitle class="text-base leading-snug font-semibold">Make version {{ target?.version }} the active template?</AlertDialogTitle>
                <AlertDialogDescription class="text-[13px] text-muted-foreground">
                    Every section under this company renders from it. Nothing about the version changes, and the version that is active now is kept.
                </AlertDialogDescription>

                <div class="space-y-1.5">
                    <Label for="activate-reason" class="text-[13px]">Reason for the record</Label>
                    <Textarea
                        id="activate-reason"
                        v-model="reason"
                        rows="2"
                        data-testid="activate-reason"
                        aria-describedby="activate-reason-help"
                        :aria-invalid="!!activateError"
                    />
                    <p id="activate-reason-help" class="text-xs text-muted-foreground">Saved in the audit log with the change.</p>
                    <p v-if="activateError" class="text-xs text-destructive" role="alert" data-testid="activate-error">{{ activateError }}</p>
                </div>

                <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                    <AlertDialogCancel :disabled="activating" class="mt-0">Cancel</AlertDialogCancel>
                    <Button :disabled="reasonBlank || activating" data-testid="activate-confirm" @click="confirmActivate">
                        <PhCircleNotch v-if="activating" class="animate-spin" aria-hidden="true" />
                        {{ activating ? 'Activating' : 'Make active' }}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </div>
</template>
