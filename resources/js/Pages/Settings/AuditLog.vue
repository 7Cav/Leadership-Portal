<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { forumDate } from '../../lib/forumDate';

// Flat entries + option lists (mirrors `StaffGrants.vue`'s flat-props pattern). `entries` is a
// Laravel paginator serialized as-is — `data` plus the standard pagination metadata — so no
// reshaping happens here.
const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    actorOptions: { type: Array, required: true },
    actionOptions: { type: Array, required: true },
});

const filters = reactive({
    actor_id: props.filters.actor_id ?? null,
    action: props.filters.action ?? null,
    target_type: props.filters.target_type ?? '',
    target_id: props.filters.target_id ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

function applyFilters() {
    router.get('/settings/audit-log', filters, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    filters.actor_id = null;
    filters.action = null;
    filters.target_type = '';
    filters.target_id = '';
    filters.date_from = '';
    filters.date_to = '';
    applyFilters();
}

function goTo(url) {
    if (url === null) {
        return;
    }
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Settings · Audit log · 7Cav Leadership Portal" />

    <div class="min-h-screen bg-bg p-6 text-white">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gold">Settings · Audit log</h1>
                <p class="mt-1 text-sm text-gray-400">Every audited action, filterable by actor, action, target, and date range.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded border border-gold-soft bg-panel px-2.5 py-1 text-xs text-gold">
                🔒 Admin only
            </span>
        </div>

        <div class="rounded-lg border border-border bg-panel p-3">
            <form data-testid="filter-form" class="mb-3 grid gap-2 border-b border-border pb-3 sm:grid-cols-3 lg:grid-cols-6" @submit.prevent="applyFilters">
                <label class="block text-xs text-gray-400">
                    Actor
                    <select v-model="filters.actor_id" data-testid="filter-actor_id" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm">
                        <option :value="null">— any —</option>
                        <option v-for="actor in actorOptions" :key="actor.id" :value="actor.id">{{ actor.username }}</option>
                    </select>
                </label>

                <label class="block text-xs text-gray-400">
                    Action
                    <select v-model="filters.action" data-testid="filter-action" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm">
                        <option :value="null">— any —</option>
                        <option v-for="action in actionOptions" :key="action" :value="action">{{ action }}</option>
                    </select>
                </label>

                <label class="block text-xs text-gray-400">
                    Target type
                    <input v-model="filters.target_type" data-testid="filter-target_type" type="text" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm" />
                </label>

                <label class="block text-xs text-gray-400">
                    Target id
                    <input v-model="filters.target_id" data-testid="filter-target_id" type="text" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm" />
                </label>

                <label class="block text-xs text-gray-400">
                    From
                    <input v-model="filters.date_from" data-testid="filter-date_from" type="date" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm" />
                </label>

                <label class="block text-xs text-gray-400">
                    To
                    <input v-model="filters.date_to" data-testid="filter-date_to" type="date" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm" />
                </label>

                <div class="col-span-full flex gap-2">
                    <button type="submit" data-testid="apply-filters" class="rounded bg-gold px-3 py-1.5 text-sm font-medium text-bg">Apply filters</button>
                    <button type="button" data-testid="clear-filters" class="rounded border border-border px-3 py-1.5 text-sm text-gray-300" @click="clearFilters">Clear</button>
                </div>
            </form>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-xs text-gray-500">
                        <th class="px-2 py-1 font-normal">Actor</th>
                        <th class="px-2 py-1 font-normal">Action</th>
                        <th class="px-2 py-1 font-normal">Target</th>
                        <th class="px-2 py-1 font-normal">Reason</th>
                        <th class="px-2 py-1 font-normal">Digest</th>
                        <th class="px-2 py-1 font-normal">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id" :data-testid="`entry-row-${entry.id}`" class="border-t border-border align-top">
                        <td class="px-2 py-2">{{ entry.actor }}</td>
                        <td class="px-2 py-2">{{ entry.action }}</td>
                        <td class="px-2 py-2">{{ entry.target_type }}#{{ entry.target_id }}</td>
                        <td class="px-2 py-2">{{ entry.reason ?? '—' }}</td>
                        <td class="px-2 py-2 font-mono text-xs text-gray-400">{{ entry.content_digest }}</td>
                        <td class="px-2 py-2">{{ forumDate(entry.created_at) }}</td>
                    </tr>
                    <tr v-if="entries.data.length === 0">
                        <td colspan="6" class="px-2 py-4 text-center text-sm text-gray-500">No audit entries match these filters.</td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-3 flex items-center justify-between text-xs text-gray-400">
                <span>{{ entries.total }} total, page {{ entries.current_page }} of {{ entries.last_page }}</span>
                <div class="flex gap-2">
                    <button
                        type="button"
                        data-testid="prev-page"
                        :disabled="!entries.prev_page_url"
                        class="rounded border border-border px-2 py-1 disabled:opacity-40"
                        @click="goTo(entries.prev_page_url)"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        data-testid="next-page"
                        :disabled="!entries.next_page_url"
                        class="rounded border border-border px-2 py-1 disabled:opacity-40"
                        @click="goTo(entries.next_page_url)"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
