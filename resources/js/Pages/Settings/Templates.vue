<script setup>
import { computed, nextTick, onBeforeUnmount, ref, toRef, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { refDebounced } from '@vueuse/core';
import { PhCaretDown, PhCheck, PhCircleNotch, PhCopy, PhFileCode, PhLockSimple, PhPlus, PhWarning } from '@phosphor-icons/vue';
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { render, sampleContext, templateLegend } from '@/lib/bbcode';
import { rollcallContext } from '@/lib/rollcallBbcode';
import { useViewAs } from '@/composables/useViewAs';
import BbcodePreview from '@/components/BbcodePreview.vue';
import LineDiff from '@/components/LineDiff.vue';
import EmptyState from '@/components/EmptyState.vue';
import { toast } from 'vue-sonner';
import InlineNotice from '@/components/InlineNotice.vue';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { copyText } from '@/lib/clipboard';

// Rollcall template authoring (Story 3.5, #375, AD-17). Everything is shaped by `Settings\TemplatesController`. A
// company keeps named templates, the regiment default always shows beside them, and the company's command chooses which
// its sections may use. A save is always a new version and a rollback only moves the template's active pointer: the
// page has no way to change a version that exists. The server checks who may write on every request (`canManage` and
// `canManageCompany` only decide what is shown).
//
// While someone previews a leader (view-as, 1.13) the editor stays on the page but disabled, with the reason on a
// tooltip, as on the roster: the page explains why it cannot act instead of silently losing the controls.
const props = defineProps({
    artifactType: { type: Object, required: true },
    companies: { type: Array, required: true },
    company: { type: Number, default: null },
    // The regiment default first, then the company's own: `[{ id, name, isRegimentDefault, enabled, isCompanyActive, version }]`.
    templates: { type: Array, default: () => [] },
    // The template open in the editor.
    template: { type: Number, default: null },
    active: { type: Object, default: null },
    history: { type: Array, required: true },
    maxBodyLength: { type: Number, required: true },
    maxNameLength: { type: Number, default: 60 },
    // A template to start from (Story 3.10).
    starter: { type: String, required: true },
    isAdmin: { type: Boolean, required: true },
    // Whether the viewer may edit the open template and roll it back, and add and switch the company's templates.
    canManage: { type: Boolean, default: false },
    canManageCompany: { type: Boolean, default: false },
    // The company's sections the viewer could open on the rollcall page, to preview with instead of the sample:
    // `[{ id, designation, name, platoon }]`.
    previewSections: { type: Array, default: () => [] },
});

const form = useForm({
    template_id: props.template,
    body: props.active?.body ?? '',
    reason: '',
});

// Another company or template, or a new active version after a save or rollback, starts the editor from that version's
// text.
watch(
    () => [props.company, props.template, props.active?.id],
    () => {
        form.template_id = props.template;
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

// The template open in the editor, and opening another, asking first when the editor holds unsaved text.
const openTemplate = computed(() => props.templates.find((item) => item.id === props.template) ?? null);
function openOther(item) {
    if (item.id === props.template) return;
    if (unsaved.value && !window.confirm('Your template changes are not saved. Open another template and lose them?')) return;

    router.get('/settings/templates', { company: props.company, template: item.id }, { preserveScroll: true });
}

// Switching a template on or off for the company's sections, and making one the company's default. Each is one audited
// write; the page reloads its props from the answer.
const busy = ref(null);
function companyWrite(url, data, failed) {
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => {
            busy.value = url;
        },
        onFinish: () => {
            busy.value = null;
        },
        onError: (errors) => toast.error(failed, { description: Object.values(errors)[0] }),
        onHttpException: onHttpException(failed, (message) => toast.error(message)),
    });
}
const switchTemplate = (item) => companyWrite(`/settings/templates/${item.id}/switch`, { enabled: !item.enabled }, 'The template was not switched');
const makeCompanyDefault = (item) => companyWrite(`/settings/templates/${item.id}/company-active`, {}, 'The company default was not changed');

// A new template starts as a copy of the one open (or the starter), under a name, with a reason for the record.
const creating = ref(false);
const createForm = useForm({ company_id: props.company, name: '', body: '', reason: '' });
function startCreate() {
    createForm.reset();
    createForm.clearErrors();
    creating.value = true;
}
function create() {
    createForm.company_id = props.company;
    createForm.body = props.active?.body ?? props.starter;
    createForm.post('/settings/templates/new', {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
        },
        onHttpException: onHttpException('The template was not added', (message) => toast.error(message)),
    });
}

function save() {
    form.post('/settings/templates', {
        preserveScroll: true,
        onHttpException: onHttpException('The template was not saved', (message) => toast.error(message)),
    });
}

const { active: previewing, subject: previewSubject } = useViewAs();
// Every write is refused during a preview, so the controls show but stay disabled then.
const readOnlyPreview = computed(() => previewing.value);
const showEditor = computed(() => props.canManage || previewing.value);
const showCompanyControls = computed(() => props.canManageCompany || previewing.value);
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

// Which version is compared line by line with the active one, so an Admin sees exactly what a rollback would change.
const comparing = ref(null);
const toggleCompare = (version) => {
    comparing.value = comparing.value === version.id ? null : version.id;
};

// Every code the template language accepts, grouped, with what it means and an example (Story 3.10). It is built from the
// lists the parser itself reads (`lib/bbcode.js`, held to the server's by the shared cases), so it cannot list a code
// the save would refuse or miss one it accepts. The codes are strings here because Vue's own template syntax would read
// double braces written in the markup as an interpolation.
const legend = templateLegend();
const EXAMPLES = { print: '{{name}}', loop: '{{#troopers}}...{{/troopers}}', inverted: '{{^name}}...{{/name}}' };

// Puts one code on the clipboard, with the same toasts as every other copy button; its button also shows a tick for a
// moment after.
const copiedCode = ref(null);
let copiedTimer = null;
async function copyCode(code) {
    if (!(await copyText(code, 'Code copied', { description: code, failed: 'Could not copy the code', fallback: 'Type it by hand instead.' }))) return;
    copiedCode.value = code;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => {
        copiedCode.value = null;
    }, 1500);
}
onBeforeUnmount(() => clearTimeout(copiedTimer));

// Clicking a code puts it in the editor at the cursor, in place of any selection, as typing it would: a section's open
// and close tags go around the selection, with the cursor between them when nothing is selected. The editor keeps the
// focus with the cursor after what went in. Only an Admin who can edit gets this; anyone else can still copy a code.
const bodyField = ref(null);
const canInsert = computed(() => showEditor.value && !readOnlyPreview.value);
async function insertCode(entry) {
    const field = bodyField.value?.$el;
    if (!canInsert.value || !field) return;

    const start = field.selectionStart ?? form.body.length;
    const end = field.selectionEnd ?? start;
    const inserted = entry.close === '' ? entry.open : `${entry.open}${form.body.slice(start, end)}${entry.close}`;
    if (form.body.length - (end - start) + inserted.length > props.maxBodyLength) return;

    // A section with nothing selected leaves the cursor between its tags, ready for what goes inside.
    const caret = entry.close !== '' && start === end ? start + entry.open.length : start + inserted.length;

    // `execCommand('insertText')` is deprecated, but it is still the only way to edit a textarea through the browser's
    // own editing, so the insert joins the native undo stack and Ctrl+Z takes it back. It fires an `input` event, which
    // updates `form.body` through v-model like typing does. Where it is missing or refuses (happy-dom in the tests),
    // the text is set directly instead, which works the same except for undo.
    field.focus();
    field.setSelectionRange(start, end);
    const native = typeof document.execCommand === 'function' && document.execCommand('insertText', false, inserted);
    if (!native) {
        form.body = form.body.slice(0, start) + inserted + form.body.slice(end);
        await nextTick();
        field.focus();
    }
    field.setSelectionRange(caret, caret);
}

// The template filled with a made-up section (the legend's examples), drawn as the forum shows the post. It follows the
// editor a moment after the Admin stops typing, so a long template is not redrawn on every key.
const SAMPLE = sampleContext();
const typedBody = refDebounced(toRef(form, 'body'), 250);
const previewSource = computed(() => (showEditor.value ? typedBody.value : (props.active?.body ?? '')));

// The preview is filled with one of the company's real sections for this week: the first one the viewer can open (the
// first section of the first platoon, as the server orders them) when the page opens, or the one picked. Its rows are
// asked for from the same builder as the rollcall page, and made into the template's values by the same
// `rollcallContext()` the rollcall page uses, so the preview is the post that section would copy now. The sample stays
// for a company with no section the viewer can open, while a section loads, and when it is picked.
const previewWith = ref('');
const realSection = ref(null);
const realState = ref('idle');
let realRequest = 0;

const REAL_FAILURES = {
    forbidden: 'That section is no longer in your scope.',
    missing: 'That section is no longer under this company.',
    signedOut: 'Your session has ended. Sign in again to load the section.',
    error: 'The section could not be loaded. Try again in a moment.',
};

async function loadPreview(id) {
    const request = ++realRequest;
    previewWith.value = id;
    realSection.value = null;
    realState.value = id === '' ? 'idle' : 'loading';
    if (id === '') return;

    try {
        const response = await fetch(`/settings/templates/preview/${id}?company=${props.company}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (request !== realRequest) return;
        if (!response.ok) {
            realState.value = { 403: 'forbidden', 404: 'missing', 401: 'signedOut', 419: 'signedOut' }[response.status] ?? 'error';

            return;
        }
        realSection.value = rollcallContext(await response.json());
        realState.value = 'ready';
    } catch (error) {
        console.error(`Templates: loading section ${id} for the preview failed`, error);
        if (request === realRequest) realState.value = 'error';
    }
}

const choosePreview = (event) => loadPreview(event.target.value);
const firstSection = () => (props.previewSections.length ? String(props.previewSections[0].id) : '');

// Another company has other sections, so the preview starts again from that company's first one.
watch(
    () => props.company,
    () => loadPreview(firstSection()),
);
loadPreview(firstSection());

const previewContext = computed(() => (realState.value === 'ready' ? realSection.value : SAMPLE));
const preview = computed(() => (previewSource.value.trim() === '' ? null : render(previewSource.value, previewContext.value)));
const troopersLabel = (count) => `${count} trooper${count === 1 ? '' : 's'}`;
const sectionLabel = (section) => (section.designation === section.name ? section.name : `${section.designation} ${section.name}`);

// Fills the editor with the default template (the starter the server sends), only when an Admin asks, so nobody saves
// it unread. It only fills the editor: saving it is still a new version with a reason. When the editor holds edits that
// are not saved, it asks first, since those edits would be lost.
const confirmingStarter = ref(false);
const unsavedEdits = computed(() => form.body !== (props.active?.body ?? '') && form.body !== props.starter);
function useStarter() {
    if (unsavedEdits.value) {
        confirmingStarter.value = true;

        return;
    }
    form.body = props.starter;
}
function confirmStarter() {
    form.body = props.starter;
    confirmingStarter.value = false;
}

// A company with none of its own templates switched on falls back to the regiment default (#375).
const nothingSwitchedOn = computed(() => props.templates.every((item) => item.isRegimentDefault || !item.enabled));
const NO_TEXT_READER = "The company's command can write it here.";

const companyLabel = (company) => (company.callsign ? `${company.callsign} ${company.name}` : company.name);
</script>

<template>
    <Head title="Settings · Templates · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Templates</h1>
                <p class="mt-1 max-w-[80ch] text-[13px] text-muted-foreground">
                    The {{ artifactType.label }} a section's rollcall post renders from. A company keeps its own templates and switches on the ones its
                    sections may use; a section uses its pick, else the company default, else the regiment default. An edit saves a new version and
                    makes it the active one; older versions are kept as they were.
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

            <Card class="min-w-0 gap-0 py-0" data-testid="template-list">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-3.5 pb-2">
                    <h2 class="text-[15px] font-semibold">Templates</h2>
                    <template v-if="showCompanyControls">
                        <TooltipProvider v-if="readOnlyPreview">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button variant="outline" size="sm" aria-disabled="true" class="cursor-not-allowed opacity-60" data-testid="new-template" @click.prevent>
                                        <PhLockSimple aria-hidden="true" />New template
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>{{ readOnlyReason }}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <Button v-else variant="outline" size="sm" data-testid="new-template" @click="startCreate"><PhPlus aria-hidden="true" />New template</Button>
                    </template>
                </div>
                <ul class="border-t border-border">
                    <li
                        v-for="item in templates"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/60 px-4 py-2.5 text-[13px] last:border-b-0"
                        :class="item.id === template ? 'bg-muted/40' : ''"
                        :data-testid="`template-row-${item.id}`"
                    >
                        <button
                            type="button"
                            class="rounded-sm text-left font-medium outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring/50"
                            :aria-current="item.id === template ? 'true' : undefined"
                            :data-testid="`open-template-${item.id}`"
                            @click="openOther(item)"
                        >
                            {{ item.name }}
                        </button>
                        <Badge v-if="item.isRegimentDefault" variant="outline" data-testid="badge-regiment-default">Regiment default</Badge>
                        <Badge v-if="item.isCompanyActive" variant="success" data-testid="badge-company-default">Company default</Badge>
                        <Badge v-if="!item.enabled" variant="outline" class="text-muted-foreground" data-testid="badge-off">Off for sections</Badge>
                        <span v-if="item.version" class="text-xs text-muted-foreground">Version {{ item.version }}</span>
                        <div v-if="showCompanyControls && !item.isRegimentDefault" class="ml-auto flex flex-wrap items-center gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="readOnlyPreview || busy !== null"
                                :title="readOnlyPreview ? readOnlyReason : undefined"
                                :data-testid="`switch-${item.id}`"
                                @click="switchTemplate(item)"
                            >
                                {{ item.enabled ? 'Switch off' : 'Switch on' }}
                            </Button>
                            <Button
                                v-if="!item.isCompanyActive"
                                variant="ghost"
                                size="sm"
                                :disabled="readOnlyPreview || busy !== null"
                                :title="readOnlyPreview ? readOnlyReason : undefined"
                                :data-testid="`make-default-${item.id}`"
                                @click="makeCompanyDefault(item)"
                            >
                                Make company default
                            </Button>
                        </div>
                    </li>
                </ul>
                <p v-if="nothingSwitchedOn" class="border-t border-border px-4 py-2.5 text-xs text-muted-foreground" data-testid="nothing-switched-on">
                    Nothing of this company's is switched on, so its sections use the regiment default.
                </p>
            </Card>

            <div class="grid items-start gap-5 lg:grid-cols-[1.2fr_0.8fr]">
                <Card class="min-w-0 gap-0 p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="text-[15px] font-semibold" data-testid="open-template-name">{{ openTemplate?.name ?? 'Active template' }}</h2>
                        <Badge v-if="active" variant="success" data-testid="active-version">Version {{ active.version }}</Badge>
                    </div>

                    <p v-if="!active" class="mb-3 text-[13px] text-muted-foreground" data-testid="no-active">
                        This template has no text yet. {{ canManage ? 'Write it below.' : NO_TEXT_READER }}
                    </p>
                    <p v-else-if="openTemplate?.isRegimentDefault && !canManage" class="mb-3 text-[13px] text-muted-foreground" data-testid="default-note">
                        Every company can use the regiment default. Only an Admin edits it.
                    </p>

                    <form v-if="showEditor" class="space-y-3" data-testid="template-form" @submit.prevent="save">
                        <div class="grid gap-1.5">
                            <Label for="template-body">Template</Label>
                            <Textarea
                                id="template-body"
                                ref="bodyField"
                                v-model="form.body"
                                data-testid="template-body"
                                rows="16"
                                class="font-mono text-xs"
                                :maxlength="maxBodyLength"
                                :disabled="readOnlyPreview"
                                :aria-invalid="!!form.errors.body"
                            />
                            <Button v-if="!readOnlyPreview" type="button" variant="outline" size="sm" class="justify-self-start" data-testid="use-starter" @click="useStarter">
                                Start from the default
                            </Button>
                            <p v-if="form.errors.body" class="text-xs text-destructive" data-testid="error-body">{{ form.errors.body }}</p>
                            <p v-if="form.errors.template_id" class="text-xs text-destructive" data-testid="error-template_id">{{ form.errors.template_id }}</p>
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
                            <p class="text-xs text-muted-foreground" data-testid="template-preview-note">
                                <template v-if="realState === 'ready'">
                                    As the forum shows it, filled with {{ realSection.unit }}'s {{ troopersLabel(realSection.troopers.length) }} and their marks this week.
                                </template>
                                <template v-else>As the forum shows it, filled with a sample section of {{ SAMPLE.troopers.length }} troopers.</template>
                                Updates as you type.
                            </p>
                        </div>
                        <div v-if="previewSections.length" class="mb-3 flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                            <Label for="template-preview-with" class="text-xs font-normal text-muted-foreground">Fill with</Label>
                            <div class="min-w-0 flex-1 sm:max-w-xs">
                                <NativeSelect
                                    id="template-preview-with"
                                    data-testid="template-preview-with"
                                    class="text-xs"
                                    :model-value="previewWith"
                                    :aria-busy="realState === 'loading'"
                                    @change="choosePreview"
                                >
                                    <option value="">Sample section</option>
                                    <option v-for="section in previewSections" :key="section.id" :value="String(section.id)">{{ sectionLabel(section) }}, this week</option>
                                </NativeSelect>
                            </div>
                            <span v-if="realState === 'loading'" class="flex items-center gap-1.5 text-xs text-muted-foreground" role="status" data-testid="template-preview-loading">
                                <PhCircleNotch class="size-3.5 animate-spin" aria-hidden="true" />Loading the section
                            </span>
                        </div>
                        <InlineNotice v-if="REAL_FAILURES[realState]" :icon="PhWarning" tone="warning" role="status" class="mb-3" data-testid="template-preview-section-error">
                            {{ REAL_FAILURES[realState] }} The preview uses the sample section until then.
                        </InlineNotice>
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
                                    <TableCell class="px-4 text-right">
                                        <div class="flex flex-wrap items-center justify-end gap-x-1 gap-y-0.5">
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
                                            <Button
                                                v-if="active && !version.isActive"
                                                variant="ghost"
                                                size="sm"
                                                :aria-expanded="comparing === version.id"
                                                :aria-controls="`version-compare-${version.id}`"
                                                :data-testid="`compare-${version.id}`"
                                                @click="toggleCompare(version)"
                                            >
                                                {{ comparing === version.id ? 'Hide comparison' : 'Compare with active' }}
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
                                        </div>
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
                                <TableRow v-if="active && comparing === version.id && !version.isActive" class="hover:bg-transparent">
                                    <TableCell colspan="3" class="px-4 pb-3 whitespace-normal">
                                        <section :id="`version-compare-${version.id}`" :aria-label="`Version ${version.version} compared with the active version ${active.version}`" :data-testid="`version-compare-${version.id}`">
                                            <p class="mb-1.5 text-xs text-muted-foreground">
                                                From the active version {{ active.version }} to version {{ version.version }}:
                                                <span class="font-semibold text-success">+</span> lines are only in version {{ version.version }},
                                                <span class="font-semibold text-destructive">&minus;</span> lines only in version {{ active.version }}.
                                            </p>
                                            <LineDiff :before="active.body" :after="version.body" />
                                        </section>
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
                        is not set. A code that is not listed here is refused when you save.<template v-if="canInsert">
                            Click a code to put it in the template at the cursor.</template>
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
                                        <button
                                            v-if="canInsert"
                                            type="button"
                                            class="rounded-sm text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                                            :aria-label="`Insert ${entry.code} in the template`"
                                            :data-testid="`legend-insert-${entry.name}`"
                                            @click="insertCode(entry)"
                                        >
                                            <code class="block cursor-pointer rounded-sm border border-border bg-muted px-1.5 py-0.5 font-mono text-xs break-all text-foreground transition-colors hover:border-ring hover:bg-accent" data-testid="legend-code">{{ entry.code }}</code>
                                        </button>
                                        <code v-else class="rounded-sm border border-border bg-muted px-1.5 py-0.5 font-mono text-xs break-all text-foreground" data-testid="legend-code">{{
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
                    Every section using this template renders from it. Nothing about the version changes, and the version that is active now is kept.
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
        <AlertDialog :open="creating" @update:open="(open) => !open && !createForm.processing && (creating = false)">
            <AlertDialogContent class="sm:max-w-md" data-testid="create-dialog">
                <AlertDialogTitle class="text-base leading-snug font-semibold">New template</AlertDialogTitle>
                <AlertDialogDescription class="text-[13px] text-muted-foreground">
                    It starts as a copy of {{ openTemplate?.name ?? 'the default' }}, switched on for this company's sections. Edit it once it is saved.
                </AlertDialogDescription>
                <form class="space-y-3" @submit.prevent="create">
                    <div class="grid gap-1.5">
                        <Label for="create-name" class="text-[13px]">Name</Label>
                        <Input id="create-name" v-model="createForm.name" data-testid="create-name" :maxlength="maxNameLength" :aria-invalid="!!createForm.errors.name" />
                        <p v-if="createForm.errors.name" class="text-xs text-destructive" role="alert" data-testid="create-error-name">{{ createForm.errors.name }}</p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="create-reason" class="text-[13px]">Reason for the record</Label>
                        <Textarea id="create-reason" v-model="createForm.reason" rows="2" data-testid="create-reason" :aria-invalid="!!createForm.errors.reason" />
                        <p v-if="createForm.errors.reason" class="text-xs text-destructive" role="alert">{{ createForm.errors.reason }}</p>
                        <p v-if="createForm.errors.body" class="text-xs text-destructive" role="alert">{{ createForm.errors.body }}</p>
                    </div>
                    <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                        <AlertDialogCancel :disabled="createForm.processing" class="mt-0">Cancel</AlertDialogCancel>
                        <Button type="submit" data-testid="create-confirm" :disabled="createForm.processing || !createForm.name.trim() || !createForm.reason.trim()">
                            <PhCircleNotch v-if="createForm.processing" class="animate-spin" aria-hidden="true" />Add template
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
        <AlertDialog :open="confirmingStarter" @update:open="(open) => (confirmingStarter = open)">
            <AlertDialogContent class="sm:max-w-md" data-testid="starter-dialog">
                <AlertDialogTitle class="text-base leading-snug font-semibold">Replace your edits with the default template?</AlertDialogTitle>
                <AlertDialogDescription class="text-[13px] text-muted-foreground">
                    The editor has changes that are not saved, and they will be lost. Nothing is saved until you save a new version.
                </AlertDialogDescription>
                <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                    <AlertDialogCancel class="mt-0">Keep my edits</AlertDialogCancel>
                    <Button data-testid="starter-confirm" @click="confirmStarter">Replace</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </div>
</template>
