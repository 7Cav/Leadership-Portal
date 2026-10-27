<script setup>
import { Head, Link } from "@inertiajs/vue3";

// One guide (#409). The body is HTML the server rendered from markdown with raw HTML stripped; it is shown as is and
// never compiled as a Vue template. The agreed design lands with #410.
defineProps({
    audience: { type: String, required: true },
    contents: { type: Array, required: true },
    checklist: { type: Array, required: true },
    // `{ title, summary, url, section: { key, title }, audience, page, html, headings, checklist }`
    guide: { type: Object, required: true },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
});
</script>

<template>
    <Head :title="`${guide.title} · Docs · 7Cav Leadership Portal`" />
    <article class="max-w-[680px]">
        <h1 class="text-2xl font-semibold tracking-tight">{{ guide.title }}</h1>
        <p class="mt-2 text-muted-foreground">{{ guide.summary }}</p>
        <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped (#408) -->
        <div class="mt-6" v-html="guide.html" />
        <nav class="mt-10 flex justify-between gap-3" aria-label="More guides">
            <Link v-if="previous" :href="previous.url"
                >Previous: {{ previous.title }}</Link
            >
            <Link v-if="next" :href="next.url" class="ml-auto"
                >Next: {{ next.title }}</Link
            >
        </nav>
    </article>
</template>
