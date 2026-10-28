<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { PhArrowRight, PhBookOpenText, PhClipboardText, PhFileText, PhUsersThree } from '@phosphor-icons/vue';
import DocsLayout from '@/components/docs/DocsLayout.vue';
import StartHere from '@/components/docs/StartHere.vue';
import { homeGroups, roleLabel } from '@/lib/docs';

// Docs home (#410, agreed design): "What do you need to do?", the viewer's role, and their guides grouped as Every
// week, When it comes up, For everyone and (Admin only) Admin. A group with nothing in it for this viewer is left out.
const props = defineProps({
    // `everyone`, `leader` or `admin`: which guides this viewer reads.
    audience: { type: String, required: true },
    // `[{ key, title, guides: [{ title, summary, url, audience, weekly }] }]`, only sections the viewer has a guide in.
    contents: { type: Array, required: true },
    // `[{ id, label, url }]`: the Start here items for this viewer (#412).
    checklist: { type: Array, required: true },
    feedbackUrl: { type: String, default: null },
});

const groups = computed(() => homeGroups(props.contents));

// The icon of a weekly task's row, by the section it is in.
const ICONS = { 'weekly-rollcall': PhClipboardText, 'weekly-report': PhFileText, 'your-troopers': PhUsersThree };
</script>

<template>
    <Head title="Docs · 7Cav Leadership Portal" />

    <DocsLayout :contents="contents">
        <section class="max-w-[680px]" data-testid="docs-home">
            <span class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-primary/18 px-1.5 text-xs font-medium text-gold-ink">
                <PhBookOpenText aria-hidden="true" />Docs
            </span>
            <h1 class="mt-1.5 mb-1.5 text-[32px] leading-tight font-semibold tracking-tight">What do you need to do?</h1>
            <p class="mb-6 text-base text-muted-foreground">
                Guides for the Leadership Portal, shown for your role: <b class="text-foreground" data-testid="docs-role">{{ roleLabel(audience) }}</b>.
            </p>

            <div v-for="group in groups" :key="group.id" class="mb-6" :data-testid="`docs-group-${group.id}`">
                <h2 class="mb-2 text-[15px] font-semibold tracking-tight">{{ group.title }}</h2>
                <div v-if="group.rows" class="rounded-lg border border-border bg-card p-1.5">
                    <Link
                        v-for="guide in group.guides"
                        :key="guide.url"
                        :href="guide.url"
                        class="flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-accent"
                    >
                        <component :is="ICONS[guide.section] ?? PhBookOpenText" class="size-5 flex-none text-[var(--nav-active-icon)]" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block font-medium">{{ guide.title }}</span>
                            <small class="block text-[12.5px] leading-snug text-muted-foreground">{{ guide.summary }}</small>
                        </span>
                        <PhArrowRight class="ml-auto flex-none text-muted-foreground" aria-hidden="true" />
                    </Link>
                </div>
                <ul v-else class="gap-x-5 sm:columns-2">
                    <li v-for="guide in group.guides" :key="guide.url" class="break-inside-avoid">
                        <Link :href="guide.url" class="block rounded-md px-2 py-1.5 text-[13.5px] text-muted-foreground hover:bg-accent hover:text-foreground">
                            {{ guide.title }}
                        </Link>
                    </li>
                </ul>
            </div>

            <p v-if="!groups.length" class="text-sm text-muted-foreground">No guides have been written for your role yet.</p>
        </section>

        <template v-if="checklist.length" #rail>
            <StartHere :checklist="checklist" :audience="audience" />
        </template>
    </DocsLayout>
</template>
