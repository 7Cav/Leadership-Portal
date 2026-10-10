<script setup>
import { ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhFileCode, PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/components/EmptyState.vue';
import { forumDate } from '@/lib/forumDate';

// Rollcall template authoring (Story 3.5, AD-17). Everything is shaped by `Settings\TemplatesController`. A save is
// always a new version and a rollback only moves the active pointer: the page has no way to change a version that
// exists. The controller enforces the Admin gate on every write, so hiding the controls here is only cosmetic (AD-6).
const props = defineProps({
    artifactType: { type: Object, required: true },
    companies: { type: Array, required: true },
    company: { type: Number, default: null },
    active: { type: Object, default: null },
    history: { type: Array, required: true },
    maxBodyLength: { type: Number, required: true },
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

function chooseCompany(event) {
    router.get('/settings/templates', { company: event.target.value }, { preserveScroll: true });
}

function save() {
    form.post('/settings/templates', { preserveScroll: true });
}

// A rollback needs its own reason (AD-16) but a row has no form of its own, so it is prompted inline, as a staff
// grant's revoke is.
const activateError = ref(null);

function requestActivate(version) {
    const reason = window.prompt(`Reason for making version ${version.version} the active template?`);
    if (reason === null) {
        return; // Cancelled: a deliberate no-op, not an error.
    }
    if (reason.trim() === '') {
        activateError.value = 'A reason is required to activate a version.';
        return;
    }

    activateError.value = null;
    router.post(`/settings/templates/${version.id}/activate`, { reason }, {
        preserveScroll: true,
        onError: (errors) => {
            activateError.value = errors.reason ?? 'The activation failed. Reload the page and try again.';
        },
    });
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
                <NativeSelect id="template-company" data-testid="company-select" :model-value="company" @change="chooseCompany">
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

                    <form v-if="isAdmin" class="space-y-3" data-testid="template-form" @submit.prevent="save">
                        <div class="grid gap-1.5">
                            <Label for="template-body">Template</Label>
                            <Textarea
                                id="template-body"
                                v-model="form.body"
                                data-testid="template-body"
                                rows="16"
                                class="font-mono text-xs"
                                :maxlength="maxBodyLength"
                                :aria-invalid="!!form.errors.body"
                            />
                            <p v-if="form.errors.body" class="text-xs text-destructive" data-testid="error-body">{{ form.errors.body }}</p>
                            <p v-if="form.errors.company_id" class="text-xs text-destructive" data-testid="error-company_id">{{ form.errors.company_id }}</p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="template-reason">Reason</Label>
                            <Textarea id="template-reason" v-model="form.reason" data-testid="template-reason" rows="2" :aria-invalid="!!form.errors.reason" />
                            <p class="text-xs text-muted-foreground">Recorded in the audit log with the new version.</p>
                            <p v-if="form.errors.reason" class="text-xs text-destructive" data-testid="error-reason">{{ form.errors.reason }}</p>
                        </div>

                        <Button type="submit" data-testid="save-template" :disabled="form.processing">Save as a new version</Button>
                    </form>

                    <pre v-else-if="active" class="overflow-x-auto rounded-md border border-border bg-muted/30 p-3 font-mono text-xs whitespace-pre-wrap" data-testid="template-readonly">{{ active.body }}</pre>
                </Card>

                <Card class="min-w-0 gap-0 py-0">
                    <div class="px-4 pt-3.5 pb-2">
                        <h2 class="text-[15px] font-semibold">Versions</h2>
                    </div>
                    <p v-if="activateError" data-testid="activate-error" role="alert" class="px-4 pb-2 text-xs text-destructive">{{ activateError }}</p>

                    <Table v-if="history.length" class="text-[13px]">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Version</TableHead>
                                <TableHead class="h-8 text-xs font-medium text-muted-foreground">Saved</TableHead>
                                <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="version in history" :key="version.id" :data-testid="`version-row-${version.id}`">
                                <TableCell class="px-4 font-medium">
                                    {{ version.version }}
                                    <Badge v-if="version.isActive" variant="success" class="ml-1.5" data-testid="active-badge">Active</Badge>
                                </TableCell>
                                <TableCell class="text-muted-foreground">
                                    {{ forumDate(version.createdAt) }}<template v-if="version.createdBy"> by {{ version.createdBy }}</template>
                                </TableCell>
                                <TableCell class="px-4 text-right">
                                    <Button
                                        v-if="isAdmin && !version.isActive"
                                        variant="ghost"
                                        size="sm"
                                        :data-testid="`activate-${version.id}`"
                                        @click="requestActivate(version)"
                                    >
                                        Make active
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <EmptyState v-else :icon="PhFileCode" title="No versions yet." class="border-t border-border py-10" data-testid="no-versions" />
                </Card>
            </div>
        </template>
    </div>
</template>
