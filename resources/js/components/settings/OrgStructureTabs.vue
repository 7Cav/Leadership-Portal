<script setup>
import { Link } from '@inertiajs/vue3';

// The tabs on Org structure: the tree itself, and Placement changes (Admin-only, so the tabs show only to Admins). Each
// tab is its own page and address, so either can be bookmarked or linked.
defineProps({
    current: { type: String, required: true, validator: (value) => ['units', 'changes'].includes(value) },
});

const TABS = [
    { id: 'units', label: 'Units', href: '/settings/org-structure' },
    { id: 'changes', label: 'Placement changes', href: '/settings/placement-changes' },
];
</script>

<template>
    <nav class="flex gap-1 border-b border-border" aria-label="Org structure" data-testid="org-structure-tabs">
        <Link
            v-for="tab in TABS"
            :key="tab.id"
            :href="tab.href"
            :aria-current="tab.id === current ? 'page' : undefined"
            :data-tab="tab.id"
            :class="[
                '-mb-px border-b-2 px-3 py-2 text-[13px] font-medium transition-colors',
                tab.id === current ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
            ]"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
