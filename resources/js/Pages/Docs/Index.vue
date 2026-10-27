<script setup>
import { Head, Link } from "@inertiajs/vue3";

// Docs home (#409). A plain list of the guides this viewer may read; the agreed design lands with #410.
defineProps({
    // `everyone`, `leader` or `admin`: which guides this viewer reads.
    audience: { type: String, required: true },
    // `[{ key, title, guides: [{ title, summary, url }] }]`, only sections the viewer has a guide in.
    contents: { type: Array, required: true },
    // `[{ id, label, url }]`: the Start here items for this viewer.
    checklist: { type: Array, required: true },
});
</script>

<template>
    <Head title="Docs · 7Cav Leadership Portal" />
    <h1 class="text-2xl font-semibold tracking-tight">
        What do you need to do?
    </h1>
    <section v-for="section in contents" :key="section.key" class="mt-6">
        <h2 class="text-[15px] font-semibold">{{ section.title }}</h2>
        <ul class="mt-2 space-y-1">
            <li v-for="guide in section.guides" :key="guide.url">
                <Link
                    :href="guide.url"
                    class="underline-offset-2 hover:underline"
                    >{{ guide.title }}</Link
                >
            </li>
        </ul>
    </section>
</template>
