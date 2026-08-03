<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

// Minimal authenticated landing (Story 1.4). Real leadership screens land in later epics.
const page = usePage();
const username = computed(() => page.props.auth?.user?.username ?? 'Trooper');
</script>

<template>
    <Head title="Home · 7Cav Leadership Portal" />

    <main class="min-h-screen bg-bg text-white flex items-center justify-center p-8">
        <div class="w-full max-w-lg rounded-lg border border-border bg-panel p-8">
            <span class="inline-block rounded bg-gold px-3 py-1 text-sm font-semibold text-bg">
                Signed in
            </span>
            <h1 class="mt-4 text-2xl font-semibold text-gold">Welcome, {{ username }}.</h1>
            <p class="mt-2 text-sm text-gray-400">
                You're authenticated with 7Cav SSO. Leadership tools land here as the portal grows.
            </p>
        </div>
    </main>
</template>
