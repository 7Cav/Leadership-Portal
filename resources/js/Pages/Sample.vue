<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    appName: String,
    stack: Array,
});
</script>

<template>
    <Head title="Skeleton OK" />

    <!-- bg-gold proves an @theme token (AC-3) resolves visibly; dark page bg uses bg-bg. -->
    <main class="min-h-screen bg-bg text-white flex items-center justify-center p-8">
        <div class="w-full max-w-lg rounded-lg border border-border bg-panel p-8">
            <span class="inline-block rounded bg-gold px-3 py-1 text-sm font-semibold text-bg">
                {{ appName }}
            </span>
            <h1 class="mt-4 text-2xl font-semibold text-gold">Skeleton is alive.</h1>
            <p class="mt-2 text-sm text-gray-400">
                Controller → Inertia::render → Vue, rendered end-to-end.
            </p>
            <ul class="mt-6 space-y-1 text-sm">
                <li v-for="item in stack" :key="item" class="border-b border-border py-1">
                    {{ item }}
                </li>
            </ul>
        </div>
    </main>
</template>
