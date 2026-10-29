<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { PhLockSimple } from '@phosphor-icons/vue';
import EmptyState from '@/components/EmptyState.vue';
import { Card } from '@/components/ui/card';

// A page the previewed or acted-as identity may not open (403 or 404 for them), while view as or act as is on. The
// server sends only who is previewed or acted as, never the page's data; the banner above keeps the way out.
const props = defineProps({
    mode: { type: String, required: true },
    kind: { type: String, required: true },
});

const noun = computed(() => ({ unit: 'unit', billet: 'billet' })[props.kind] ?? 'trooper');
const verb = computed(() => (props.mode === 'actAs' ? 'acting as' : 'viewing as'));
const message = computed(() => `The ${noun.value} you are ${verb.value} does not have permission to see this page.`);
const way = computed(() => (props.mode === 'actAs' ? 'Stop acting' : 'Exit view as'));
</script>

<template>
    <Head title="No access · 7Cav Leadership Portal" />

    <Card class="py-10" data-testid="blocked-page">
        <EmptyState :icon="PhLockSimple" :title="message">Use {{ way }} in the banner to see it as yourself.</EmptyState>
    </Card>
</template>
