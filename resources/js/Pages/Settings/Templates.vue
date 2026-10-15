<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhCircleNotch, PhFileCode, PhLockSimple } from '@phosphor-icons/vue';
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useViewAs } from '@/composables/useViewAs';
import EmptyState from '@/components/EmptyState.vue';
import { toast } from 'vue-sonner';
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
    // The names a template may use, and a template to start from (Story 3.10).
    syntax: { type: Object, required: true },
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

// The placeholders as an Admin types them. They are built here because Vue's own template syntax would read the double
// braces inside a template string as the end of an interpolation.
const tag = (name) => `{{${name}}}`;
const flagBlock = (name) => `{{#${name}}}...{{/${name}}}`;
const filterTag = (filter) => `{{name|${filter}}}`;
const EXAMPLES = { print: tag('name'), loop: '{{#troopers}} ... {{/troopers}}', inverted: '{{^name}}...{{/name}}', empty: '{{^troopers}}' };

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

                    <details class="mt-4 rounded-md border border-border bg-muted/20 p-3 text-xs" data-testid="syntax-help">
                        <summary class="cursor-pointer font-medium">Placeholders you can use</summary>
                        <div class="mt-2 space-y-2 text-muted-foreground">
                            <p>
                                Write the BBCode as normal. <code class="font-mono">{{ EXAMPLES.print }}</code> prints a value, and
                                <code class="font-mono">{{ EXAMPLES.loop }}</code> repeats for each trooper. A name that is not on these lists is refused when
                                you save.
                            </p>
                            <p data-testid="syntax-values">
                                <span class="font-medium text-foreground">Anywhere:</span>
                                <code v-for="name in syntax.values" :key="name" class="mr-1.5 font-mono">{{ tag(name) }}</code>
                            </p>
                            <p data-testid="syntax-row-values">
                                <span class="font-medium text-foreground">Inside a trooper block, print:</span>
                                <code v-for="name in syntax.rowValues" :key="name" class="mr-1.5 font-mono">{{ tag(name) }}</code>
                            </p>
                            <p data-testid="syntax-row-flags">
                                <span class="font-medium text-foreground">Inside a trooper block, show only when set:</span>
                                <code v-for="name in syntax.rowFlags" :key="name" class="mr-1.5 font-mono">{{ flagBlock(name) }}</code>
                            </p>
                            <p>
                                <code class="font-mono">{{ EXAMPLES.inverted }}</code> shows when it is not set, for example
                                <code class="font-mono">{{ EXAMPLES.empty }}</code> for an empty section. Filters:
                                <code v-for="filter in syntax.filters" :key="filter" class="mr-1.5 font-mono">{{ filterTag(filter) }}</code>
                            </p>
                        </div>
                    </details>
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
