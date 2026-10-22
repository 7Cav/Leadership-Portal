<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhKey, PhLockSimple, PhPlus } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/components/EmptyState.vue';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';

// Flat grants/units/users lists (mirrors `OrgStructure.vue`'s flat-props pattern) — the target
// user and node pickers are plain `<select>`s (no roster/Trooper model exists yet for a live
// search, and the covering mockup itself only shows a plain "from roster" select).
const props = defineProps({
    grants: { type: Array, required: true },
    units: { type: Array, required: true },
    users: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
});

const form = useForm({
    user_id: null,
    unit_id: null,
    reason: '',
});

function submitGrant() {
    form.post('/settings/staff-grants', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onHttpException: onHttpException('The grant was not added', (message) => toast.error(message)),
    });
}

// A revoke needs its own reason (AD-16) but there's no per-row form in the table, so it's
// prompted inline rather than duplicating the grant form's shape for a single field.
const revokeError = ref(null);

function requestRevoke(grant) {
    const reason = window.prompt(`Reason for revoking ${grant.username}'s grant at ${grant.unit_name}?`);
    if (reason === null) {
        return; // Cancelled: a deliberate no-op, not an error.
    }
    if (reason.trim() === '') {
        revokeError.value = 'A reason is required to revoke a grant.';
        return;
    }

    revokeError.value = null;
    router.delete(`/settings/staff-grants/${grant.id}`, {
        data: { reason },
        preserveScroll: true,
        onError: (errors) => {
            revokeError.value = errors.reason ?? 'The revoke failed. Reload the page and try again; if it persists, check the server logs.';
        },
    });
}
</script>

<template>
    <Head title="Settings · Staff grants · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Staff grants</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    Give staff scope over a unit when their billet alone does not, such as S-shop staff and dual-hatted leaders.
                </p>
            </div>
            <Badge v-if="isAdmin" variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
        </div>

        <div class="grid items-start gap-5 lg:grid-cols-[1.1fr_0.9fr]">
            <Card class="min-w-0 gap-0 py-0">
                <div class="px-4 pt-3.5 pb-2">
                    <h2 class="text-[15px] font-semibold">Current grants</h2>
                </div>
                <p v-if="revokeError" data-testid="revoke-error" role="alert" class="px-4 pb-2 text-xs text-destructive">{{ revokeError }}</p>

                <Table v-if="grants.length" class="text-[13px]">
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">User</TableHead>
                            <TableHead class="h-8 text-xs font-medium text-muted-foreground">Unit</TableHead>
                            <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="grant in grants" :key="grant.id" :data-testid="`grant-row-${grant.id}`">
                            <TableCell class="px-4 font-medium">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    {{ grant.username }}
                                    <!-- A grant only counts while its holder serves; a lapsed one stays listed
                                         so an Admin can see it and revoke it on the record. -->
                                    <Badge
                                        v-if="!grant.holder_serving"
                                        variant="warning"
                                        :data-testid="`lapsed-${grant.id}`"
                                    >
                                        Lapsed: not serving
                                        <span class="sr-only">. This grant gives no scope while its holder is not on a serving Cav roster.</span>
                                    </Badge>
                                </span>
                            </TableCell>
                            <TableCell><Badge variant="outline">{{ grant.unit_name }}</Badge></TableCell>
                            <TableCell class="px-4 text-right">
                                <Button
                                    v-if="isAdmin"
                                    variant="ghost"
                                    size="sm"
                                    :data-testid="`revoke-${grant.id}`"
                                    class="text-destructive hover:text-destructive"
                                    @click="requestRevoke(grant)"
                                >
                                    Revoke
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <EmptyState v-else :icon="PhKey" title="No staff grants yet." class="border-t border-border py-10">
                    {{ isAdmin ? 'Grant a user scope over a unit with the form alongside.' : 'An Admin can grant staff scope over a unit here.' }}
                </EmptyState>
            </Card>

            <Card v-if="isAdmin" class="min-w-0 gap-0 p-4">
                <form data-testid="grant-form" class="space-y-3" @submit.prevent="submitGrant">
                    <h2 class="flex items-center gap-1.5 text-[15px] font-semibold"><PhPlus aria-hidden="true" />Grant scope</h2>

                    <div class="grid gap-1.5">
                        <Label for="grant-user">User</Label>
                        <NativeSelect id="grant-user" v-model="form.user_id" data-testid="grant-user_id" :aria-invalid="!!form.errors.user_id">
                            <option :value="null">Choose a user</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.username }}</option>
                        </NativeSelect>
                        <p v-if="form.errors.user_id" class="text-xs text-destructive" data-testid="error-user_id">{{ form.errors.user_id }}</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="grant-unit">Unit</Label>
                        <NativeSelect id="grant-unit" v-model="form.unit_id" data-testid="grant-unit_id" :aria-invalid="!!form.errors.unit_id">
                            <option :value="null">Choose a unit</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                        </NativeSelect>
                        <p v-if="form.errors.unit_id" class="text-xs text-destructive" data-testid="error-unit_id">{{ form.errors.unit_id }}</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="grant-reason">Reason</Label>
                        <Textarea id="grant-reason" v-model="form.reason" data-testid="grant-reason" rows="2" :aria-invalid="!!form.errors.reason" />
                        <p class="text-xs text-muted-foreground">Recorded in the audit log with the grant.</p>
                        <p v-if="form.errors.reason" class="text-xs text-destructive" data-testid="error-reason">{{ form.errors.reason }}</p>
                    </div>

                    <Button type="submit" data-testid="submit-grant" :disabled="form.processing">Grant scope</Button>
                </form>
            </Card>
        </div>
    </div>
</template>
