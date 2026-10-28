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
import { forumDate } from '@/lib/forumDate';

// Flat grants/units/users lists (mirrors `OrgStructure.vue`'s flat-props pattern) — the target
// user and node pickers are plain `<select>`s (no roster/Trooper model exists yet for a live
// search, and the covering mockup itself only shows a plain "from roster" select).
const props = defineProps({
    grants: { type: Array, required: true },
    units: { type: Array, required: true },
    users: { type: Array, required: true },
    // Regimental HQ staff (placed on the regiment node, serving): they read the whole regiment from that placement
    // (#499), read-only. Shown as a note, as it comes from their placement, not a grant. Sent only to someone acting
    // as Admin.
    regimentalHq: { type: Array, default: () => [] },
    // The approved Admin billets (decided by the S6, 2026-10-28): whoever holds one is an Admin. Each with the names
    // Cav shows now, who holds it, who approved it and when, and whether the viewer holds it (`own`).
    adminViaBillet: { type: Array, default: () => [] },
    // The billets in an Admin billet group waiting for a decision or rejected: the names Cav shows now, the approved
    // ones when they differ (`approvedAs`), whether the group can still make an Admin (`adminGroup`), who holds it, its
    // status, and whether the viewer holds it (`own`).
    adminBillets: { type: Array, default: () => [] },
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

// Approving or rejecting an Admin billet is audited with a reason (AD-16), prompted as a revoke is.
const billetError = ref(null);

const STATUS_LABELS = { pending: 'Waiting for an Admin', rejected: 'Rejected' };

function names(title, group) {
    return [title, group].filter(Boolean).join(' in ') || 'Not known yet';
}

function approvedLine(billet) {
    return billet.approvedAs ? `Approved as: ${names(billet.approvedAs.title, billet.approvedAs.group)}` : null;
}

function approvedOn(billet) {
    if (!billet.decidedAt) {
        return 'Not known';
    }

    return billet.decidedBy ? `${forumDate(billet.decidedAt)} by ${billet.decidedBy}` : forumDate(billet.decidedAt);
}

// Revoking an approved billet is the same audited reject, prompted from the top list, with its error shown there.
const revokeBilletError = ref(null);

function decideBillet(billet, approve, revoke = false) {
    const errorRef = revoke ? revokeBilletError : billetError;
    const verb = approve ? 'approving' : revoke ? 'revoking' : 'rejecting';
    const reason = window.prompt(`Reason for ${verb} ${billet.title || 'this billet'} as an Admin billet?`);
    if (reason === null) {
        return;
    }
    if (reason.trim() === '') {
        errorRef.value = `A reason is required to ${approve ? 'approve' : revoke ? 'revoke' : 'reject'} a billet.`;
        return;
    }

    errorRef.value = null;
    router.post(`/settings/staff-grants/admin-billets/${billet.id}/${approve ? 'approve' : 'reject'}`, { reason }, {
        preserveScroll: true,
        onError: (errors) => {
            errorRef.value = errors.adminBillet ?? errors.reason ?? 'The decision was not saved. Reload the page and try again.';
        },
    });
}

function requestRevoke(grant) {
    const reason = window.prompt(`Reason for revoking ${grant.username}'s grant at ${grant.unit_label}?`);
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
            revokeError.value = errors.reason ?? 'The revoke failed. Reload the page and try again. If it fails again, check the server logs.';
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
                    Give staff scope over a unit when their billet alone does not, such as staff department officers and dual-hatted leaders.
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

                <Table v-if="grants.length" sticky-header class="text-[13px]">
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
                            <TableCell><Badge variant="outline">{{ grant.unit_label }}</Badge></TableCell>
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
                <EmptyState v-else :icon="PhKey" title="No staff grants yet" class="border-t border-border py-10">
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
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.label }}</option>
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

        <Card v-if="isAdmin" data-testid="admin-via-billet" class="min-w-0 gap-0 py-0">
            <div class="px-4 pt-3.5 pb-2">
                <h2 class="text-[15px] font-semibold">Admin via billet</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Whoever holds one of these billets is an Admin because of the billet, not a grant. They lose it when they leave the billet
                    or stop serving, and while a renamed or moved billet waits to be approved again. Revoke a billet to end it. You cannot
                    revoke a billet you hold.
                </p>
            </div>
            <p v-if="revokeBilletError" data-testid="revoke-billet-error" role="alert" class="px-4 pb-2 text-xs text-destructive">{{ revokeBilletError }}</p>

            <Table v-if="adminViaBillet.length" class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Billet</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Held by</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Approved</TableHead>
                        <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="billet in adminViaBillet" :key="billet.id" :data-testid="`admin-via-billet-${billet.id}`">
                        <TableCell class="px-4 font-medium">
                            {{ names(billet.title, billet.group) }}
                            <Badge v-if="billet.approvedAs" variant="warning" class="ml-1.5" :data-testid="`admin-via-billet-renamed-${billet.id}`">Renamed</Badge>
                            <Badge v-if="!billet.adminGroup" variant="warning" class="ml-1.5" :data-testid="`admin-via-billet-not-admin-${billet.id}`">
                                Not an Admin group
                                <span class="sr-only">. Its holders are not Admins while the billet is in this group.</span>
                            </Badge>
                            <p v-if="billet.approvedAs" class="text-xs font-normal text-muted-foreground" :data-testid="`admin-via-billet-approved-as-${billet.id}`">
                                {{ approvedLine(billet) }}
                            </p>
                        </TableCell>
                        <TableCell class="px-4">{{ billet.holders.length ? billet.holders.join(', ') : 'Vacant' }}</TableCell>
                        <TableCell class="px-4" :data-testid="`admin-via-billet-approved-${billet.id}`">{{ approvedOn(billet) }}</TableCell>
                        <TableCell class="px-4 text-right whitespace-nowrap">
                            <span v-if="billet.own" class="text-xs text-muted-foreground" :data-testid="`admin-via-billet-own-${billet.id}`">Your billet</span>
                            <Button
                                v-else
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive"
                                :data-testid="`admin-via-billet-revoke-${billet.id}`"
                                @click="decideBillet(billet, false, true)"
                            >
                                Revoke
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <p v-else data-testid="admin-via-billet-empty" class="border-t border-border px-4 py-6 text-[13px] text-muted-foreground">
                No billet is approved yet. Approve one below to make whoever holds it an Admin.
            </p>

            <!-- #499's read scope, from placement rather than a billet: kept as a note because nothing else on the page shows it. -->
            <p v-if="regimentalHq.length" data-testid="regimental-hq" class="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                Placed on the regiment, so they read all of it, read-only, whether or not their billet is approved:
                <template v-for="(trooper, index) in regimentalHq" :key="trooper.id">
                    <span :data-testid="`regimental-hq-${trooper.id}`">{{ trooper.billet ? `${trooper.name} (${trooper.billet})` : trooper.name }}</span>{{ index < regimentalHq.length - 1 ? ', ' : '.' }}
                </template>
            </p>
        </Card>

        <Card v-if="isAdmin" data-testid="admin-billets" class="min-w-0 gap-0 py-0">
            <div class="px-4 pt-3.5 pb-2">
                <h2 class="text-[15px] font-semibold">Admin billets</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Approve a Regimental HQ or S6 HQ billet to make whoever holds it an Admin. When Cav renames the billet or moves it to
                    another group, it waits here again until an Admin approves the new names. A billet moved out of those groups cannot be
                    approved. You cannot decide on a billet you hold.
                </p>
            </div>
            <p v-if="billetError" data-testid="billet-error" role="alert" class="px-4 pb-2 text-xs text-destructive">{{ billetError }}</p>

            <Table v-if="adminBillets.length" class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Billet now</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Held by</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Status</TableHead>
                        <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="billet in adminBillets" :key="billet.id" :data-testid="`admin-billet-${billet.id}`">
                        <TableCell class="px-4 font-medium">
                            {{ names(billet.title, billet.group) }}
                            <Badge v-if="billet.approvedAs" variant="warning" class="ml-1.5" :data-testid="`admin-billet-renamed-${billet.id}`">Renamed</Badge>
                            <Badge v-if="!billet.adminGroup" variant="warning" class="ml-1.5" :data-testid="`admin-billet-not-admin-${billet.id}`">
                                Not an Admin group
                                <span class="sr-only">. It cannot be approved while the billet is in this group.</span>
                            </Badge>
                            <p v-if="billet.approvedAs" class="text-xs font-normal text-muted-foreground" :data-testid="`admin-billet-approved-as-${billet.id}`">
                                {{ approvedLine(billet) }}
                            </p>
                        </TableCell>
                        <TableCell class="px-4">{{ billet.holders.length ? billet.holders.join(', ') : 'Vacant' }}</TableCell>
                        <TableCell class="px-4" :data-testid="`admin-billet-status-${billet.id}`">{{ STATUS_LABELS[billet.status] ?? billet.status }}</TableCell>
                        <TableCell class="px-4 text-right whitespace-nowrap">
                            <span v-if="billet.own" class="text-xs text-muted-foreground" :data-testid="`admin-billet-own-${billet.id}`">Your billet</span>
                            <template v-else>
                                <Button
                                    v-if="billet.adminGroup"
                                    variant="ghost"
                                    size="sm"
                                    :data-testid="`admin-billet-approve-${billet.id}`"
                                    @click="decideBillet(billet, true)"
                                >
                                    Approve
                                </Button>
                                <Button
                                    v-if="billet.status !== 'rejected'"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    :data-testid="`admin-billet-reject-${billet.id}`"
                                    @click="decideBillet(billet, false)"
                                >
                                    Reject
                                </Button>
                            </template>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <p v-else data-testid="admin-billets-empty" class="border-t border-border px-4 py-6 text-[13px] text-muted-foreground">
                No billet is waiting for a decision. The org import lists every Regimental HQ and S6 HQ billet here.
            </p>
        </Card>
    </div>
</template>
