<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

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
    });
}

// A revoke needs its own reason (AD-16) but there's no per-row form in the table, so it's
// prompted inline rather than duplicating the grant form's shape for a single field.
const revokeError = ref(null);

function requestRevoke(grant) {
    const reason = window.prompt(`Reason for revoking ${grant.username}'s grant at ${grant.unit_name}?`);
    if (reason === null) {
        return; // Cancelled — a deliberate no-op, not an error.
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
            revokeError.value = errors.reason ?? 'Revoke failed — see server response.';
        },
    });
}
</script>

<template>
    <Head title="Settings · Staff grants · 7Cav Leadership Portal" />

    <div class="min-h-screen bg-bg p-6 text-white">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gold">Settings · Staff grants</h1>
                <p class="mt-1 text-sm text-gray-400">
                    Attach non-admin staff to a node for scope the roster can't imply — S-shop staff, dual-hats.
                </p>
            </div>
            <span
                v-if="isAdmin"
                class="inline-flex items-center gap-1.5 rounded border border-gold-soft bg-panel px-2.5 py-1 text-xs text-gold"
            >
                🔒 Admin
            </span>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-lg border border-border bg-panel p-3">
                <h2 class="mb-2 px-2 text-sm font-medium text-gray-300">Current grants</h2>
                <p v-if="revokeError" data-testid="revoke-error" class="mb-2 px-2 text-[11px] text-bad">{{ revokeError }}</p>

                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500">
                            <th class="px-2 py-1 font-normal">User</th>
                            <th class="px-2 py-1 font-normal">Node</th>
                            <th class="px-2 py-1 font-normal"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="grant in grants" :key="grant.id" :data-testid="`grant-row-${grant.id}`" class="border-t border-border">
                            <td class="px-2 py-2">{{ grant.username }}</td>
                            <td class="px-2 py-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-bg px-2.5 py-0.5 text-xs">
                                    {{ grant.unit_name }}
                                </span>
                            </td>
                            <td class="px-2 py-2 text-right">
                                <button
                                    v-if="isAdmin"
                                    type="button"
                                    :data-testid="`revoke-${grant.id}`"
                                    class="text-xs text-bad"
                                    @click="requestRevoke(grant)"
                                >
                                    Revoke
                                </button>
                            </td>
                        </tr>
                        <tr v-if="grants.length === 0">
                            <td colspan="3" class="px-2 py-4 text-center text-sm text-gray-500">No staff grants yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="isAdmin" class="rounded-lg border border-border bg-panel p-4">
                <form data-testid="grant-form" class="space-y-3" @submit.prevent="submitGrant">
                    <h3 class="text-sm font-medium text-gray-300">+ Grant scope</h3>

                    <label class="block text-xs text-gray-400">
                        User
                        <select v-model="form.user_id" data-testid="grant-user_id" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm">
                            <option :value="null">— choose a user —</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.username }}</option>
                        </select>
                        <p v-if="form.errors.user_id" class="mt-1 text-[11px] text-bad" data-testid="error-user_id">
                            {{ form.errors.user_id }}
                        </p>
                    </label>

                    <label class="block text-xs text-gray-400">
                        Node
                        <select v-model="form.unit_id" data-testid="grant-unit_id" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm">
                            <option :value="null">— choose a node —</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                        </select>
                        <p v-if="form.errors.unit_id" class="mt-1 text-[11px] text-bad" data-testid="error-unit_id">
                            {{ form.errors.unit_id }}
                        </p>
                    </label>

                    <label class="block text-xs text-gray-400">
                        Reason
                        <textarea
                            v-model="form.reason"
                            data-testid="grant-reason"
                            rows="2"
                            class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-[11px] text-bad" data-testid="error-reason">
                            {{ form.errors.reason }}
                        </p>
                    </label>

                    <button
                        type="submit"
                        data-testid="submit-grant"
                        :disabled="form.processing"
                        class="rounded bg-gold px-3 py-1.5 text-sm font-medium text-bg disabled:opacity-50"
                    >
                        Grant scope
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
